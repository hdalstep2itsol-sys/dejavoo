<?php

namespace App\Http\Middleware;

use App\Enums\IpospaysFeedHmacStatus;
use App\Services\IpospaysFeedAcknowledgement;
use App\Services\IpospaysFeedDiagnosticObserver;
use App\Services\IpospaysFeedHmacVerifier;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use JsonException;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

class VerifyIpospaysFeedHmac
{
    public function __construct(
        private readonly IpospaysFeedHmacVerifier $verifier,
        private readonly IpospaysFeedAcknowledgement $acknowledgement,
        private readonly IpospaysFeedDiagnosticObserver $diagnostics,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $rawBody = $request->getContent();
        $observationId = $this->diagnostics->observe($request, $rawBody);
        $maximumBytes = max(1, (int) config('ipospays.feed.max_payload_bytes', 262144));

        if (strlen($rawBody) > $maximumBytes) {
            $this->diagnostics->recordOutcome($observationId, 'payload_too_large');
            Log::warning('iPOSpays FEED request rejected.', ['code' => 'payload_too_large']);

            return $this->acknowledgement->payloadTooLarge();
        }

        try {
            $decoded = json_decode($rawBody, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->diagnostics->recordOutcome($observationId, 'malformed_json');
            Log::warning('iPOSpays FEED request rejected.', ['code' => 'malformed_json']);

            return $this->acknowledgement->malformedJson();
        }

        if (! $decoded instanceof stdClass) {
            $this->diagnostics->recordOutcome($observationId, 'malformed_json');
            Log::warning('iPOSpays FEED request rejected.', ['code' => 'malformed_json']);

            return $this->acknowledgement->malformedJson();
        }

        /** @var array<string, mixed> $payload */
        $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);

        $result = $this->verifier->verify($payload);
        if (! $result->verified()) {
            $this->diagnostics->recordOutcome(
                $observationId,
                $this->diagnosticOutcome($result->status),
            );
            Log::warning('iPOSpays FEED authentication rejected.', ['code' => $result->status->value]);

            return $this->acknowledgement->hmacFailure($result->status);
        }

        $request->attributes->set('ipospays_feed_payload', $payload);
        $this->diagnostics->recordOutcome($observationId, 'verified');

        return $next($request);
    }

    private function diagnosticOutcome(IpospaysFeedHmacStatus $status): string
    {
        return match ($status) {
            IpospaysFeedHmacStatus::SignatureMissing => 'signature_missing',
            IpospaysFeedHmacStatus::ProfileUnfinalized => 'provider_profile_unfinalized',
            IpospaysFeedHmacStatus::InvalidSignature,
            IpospaysFeedHmacStatus::VerificationFailed => 'verification_failed',
            IpospaysFeedHmacStatus::FeedDisabled => 'feed_disabled',
            IpospaysFeedHmacStatus::SecretMissing => 'secret_missing',
            IpospaysFeedHmacStatus::Verified => 'verified',
        };
    }
}
