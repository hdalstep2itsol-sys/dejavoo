<?php

namespace App\Services;

use App\Data\IpospaysFeedHmacResult;
use Illuminate\Http\Request;
use Illuminate\Log\LogManager;
use Throwable;

class IpospaysFeedAuthDiagnosticLogger
{
    private const MAX_FIELD_COUNT = 100;

    private const MAX_NAME_LENGTH = 128;

    private const MAX_IDENTIFIER_LENGTH = 191;

    private const MAX_USER_AGENT_LENGTH = 256;

    private const MAX_CONTENT_TYPE_LENGTH = 128;

    public function __construct(private readonly LogManager $logs) {}

    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function record(
        Request $request,
        string $rawBody,
        string $requestId,
        string $stage,
        ?array $payload = null,
        ?IpospaysFeedHmacResult $result = null,
        ?bool $jsonDecodeSucceeded = null,
    ): void {
        if (! config('ipospays.feed.auth_diagnostics', false)) {
            return;
        }

        try {
            $jsonDecodeSucceeded ??= $payload !== null;
            $context = $this->transportContext(
                $request,
                $rawBody,
                $requestId,
                $stage,
                $jsonDecodeSucceeded,
            );

            // For non-JSON input, deliberately retain transport fingerprints
            // only. Never inspect or log any portion of the body itself.
            if ($jsonDecodeSucceeded) {
                $payload ??= [];
                $context = [
                    ...$context,
                    'remote_ip' => $this->safeText($request->ip(), 64),
                    'user_agent' => $this->safeText($request->userAgent(), self::MAX_USER_AGENT_LENGTH),
                    'http_method' => $request->getMethod(),
                    'json_decode_succeeded' => true,
                    'top_level_fields' => $this->topLevelFields($payload),
                    'signature_present' => array_key_exists('signature', $payload),
                    'hmac_profile' => $this->safeText(
                        $result?->profileName ?? (string) config('ipospays.feed.hmac_profile'),
                        self::MAX_NAME_LENGTH,
                    ),
                    ...$this->safeIdentifiers($payload),
                    ...$this->verificationContext($result),
                ];
            }

            $this->logs->channel('ipospays_feed')->info(
                'iPOSpays FEED auth diagnostic.',
                $context,
            );
        } catch (Throwable) {
            // Diagnostics must never interfere with the fail-closed webhook flow.
        }
    }

    /** @return array<string, mixed> */
    private function transportContext(
        Request $request,
        string $rawBody,
        string $requestId,
        string $stage,
        bool $jsonDecodeSucceeded,
    ): array {
        return [
            'recorded_at' => now()->toIso8601String(),
            'request_id' => $requestId,
            'stage' => $stage,
            'content_type' => $this->safeText(
                $request->headers->get('Content-Type'),
                self::MAX_CONTENT_TYPE_LENGTH,
            ),
            'content_length' => $this->safeText($request->headers->get('Content-Length'), 32),
            'body_length' => strlen($rawBody),
            'body_sha256' => hash('sha256', $rawBody),
            'json_decode_succeeded' => $jsonDecodeSucceeded,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    private function topLevelFields(array $payload): array
    {
        return array_slice(array_map(
            fn (string $name): string => $this->safeText($name, self::MAX_NAME_LENGTH) ?? '',
            array_keys($payload),
        ), 0, self::MAX_FIELD_COUNT);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string|null>
     */
    private function safeIdentifiers(array $payload): array
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        return [
            'provider_event_id' => $this->safeScalar($payload['id'] ?? null),
            'event_type' => $this->safeScalar($payload['eventType'] ?? null),
            'sub_event_type' => $this->safeScalar($payload['subEventType'] ?? null),
            'request_type' => $this->safeScalar($payload['requestType'] ?? null),
            'tpn' => $this->safeScalar($data['tpn'] ?? null),
            'term_id' => $this->safeScalar($data['termId'] ?? null),
        ];
    }

    /** @return array<string, int|string|bool|null> */
    private function verificationContext(?IpospaysFeedHmacResult $result): array
    {
        return [
            'canonical_length' => $result?->canonicalLength,
            'canonical_sha256' => $result?->canonicalSha256,
            'expected_signature_fp' => $result?->expectedSignatureFingerprint,
            'received_signature_fp' => $result?->receivedSignatureFingerprint,
            'signatures_matched' => $result?->signaturesMatched,
        ];
    }

    private function safeScalar(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        return $this->safeText((string) $value, self::MAX_IDENTIFIER_LENGTH);
    }

    private function safeText(?string $value, int $maximumLength): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';

        return mb_substr($value, 0, $maximumLength);
    }
}
