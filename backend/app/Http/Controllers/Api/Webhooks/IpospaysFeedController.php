<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Data\MappedIpospaysFeedTransaction;
use App\Data\MappedIpospaysFeedUnsupportedEvent;
use App\Exceptions\IpospaysProviderProfileNotFinalizedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Webhooks\IpospaysFeedRequest;
use App\Services\IpospaysFeedAcknowledgement;
use App\Services\IpospaysFeedPayloadMapper;
use App\Services\IpospaysFeedProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class IpospaysFeedController extends Controller
{
    public function __invoke(
        IpospaysFeedRequest $request,
        IpospaysFeedPayloadMapper $mapper,
        IpospaysFeedProcessor $processor,
        IpospaysFeedAcknowledgement $acknowledgement,
    ): JsonResponse {
        $processingStage = 'payload_mapping';
        $mapped = null;

        try {
            $mapped = $mapper->map($request->feedPayload());
            $processingStage = $mapped instanceof MappedIpospaysFeedTransaction
                ? 'transaction_processing'
                : 'unsupported_event_processing';
            $result = $mapped instanceof MappedIpospaysFeedTransaction
                ? $processor->process($mapped)
                : $processor->processUnsupported($mapped);

            $processingStage = 'acknowledgement';

            return $acknowledgement->processingResult($result->status);
        } catch (IpospaysProviderProfileNotFinalizedException) {
            $event = $processor->recordPendingProviderMapping();
            Log::warning('iPOSpays FEED payload mapping rejected.', [
                'code' => 'provider_mapping_unfinalized',
                'feed_event_id' => $event->id,
            ]);

            return $acknowledgement->pendingProviderMapping();
        } catch (Throwable $exception) {
            Log::error('iPOSpays FEED processing failed.', [
                'code' => 'temporary_processing_failure',
                'request_id' => $this->safeIdentifier(
                    $request->attributes->get('ipospays_feed_request_id'),
                ),
                'processing_stage' => $processingStage,
                'exception_class' => $exception::class,
                'exception_message' => $this->safeExceptionMessage(
                    $exception->getMessage(),
                    $request->feedPayload(),
                ),
                ...$this->safeProviderIdentifiers($request->feedPayload(), $mapped),
            ]);

            return $acknowledgement->transientFailure();
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{provider_event_id: ?string, provider_transaction_id: ?string}
     */
    private function safeProviderIdentifiers(
        array $payload,
        MappedIpospaysFeedTransaction|MappedIpospaysFeedUnsupportedEvent|null $mapped,
    ): array {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        return [
            'provider_event_id' => $this->safeIdentifier(
                $mapped?->providerEventId ?? $payload['id'] ?? null,
            ),
            'provider_transaction_id' => $this->safeIdentifier(
                $mapped?->providerTransactionId ?? $data['transactionId'] ?? null,
            ),
        ];
    }

    private function safeIdentifier(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = trim((string) $value);
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', '', $value) ?? '';

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > 191) {
            return 'sha256:'.hash('sha256', $value);
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function safeExceptionMessage(string $message, array $payload): string
    {
        foreach ([
            config('ipospays.feed.basic_password'),
            config('ipospays.feed.hmac_secret'),
            ...$this->sensitivePayloadValues($payload),
        ] as $secret) {
            if (is_string($secret) && $secret !== '') {
                $message = str_replace($secret, '[REDACTED_SECRET]', $message);
            }
        }

        if ($this->isStructuredPayload($message)) {
            return 'Exception message redacted because it contained structured payload data.';
        }

        $message = preg_replace(
            [
                '/\b(?:Basic|Bearer)\s+[A-Za-z0-9+\/_=.:-]+/i',
                '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',
                '/(?<![A-Za-z0-9])\d{4,6}[\d*Xx\-\s]{4,}\d{4}(?![A-Za-z0-9])/',
                '/(?<![A-Za-z0-9])\+?\d[\d\s().-]{7,}\d(?![A-Za-z0-9])/',
                '/[\x00-\x1F\x7F]+/u',
            ],
            [
                '[REDACTED_AUTHORIZATION]',
                '[REDACTED_EMAIL]',
                '[REDACTED_CARD_DATA]',
                '[REDACTED_PHONE]',
                ' ',
            ],
            $message,
        ) ?? 'Exception message could not be safely rendered.';

        return mb_substr(trim($message), 0, 1000);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function sensitivePayloadValues(array $payload): array
    {
        $sensitiveKeys = [
            'authorization', 'signature', 'pan', 'maskedpan', 'cardtoken',
            'email', 'phone', 'cardholder', 'cardholdername', 'customername',
            'firstname', 'lastname', 'address', 'billingaddress', 'shippingaddress',
            'consumerid', 'subscriptionid', 'customer', 'customerinfo', 'billing',
            'shipping',
        ];
        $values = [];

        $this->collectSensitivePayloadValues($payload, $sensitiveKeys, $values);

        return array_values(array_unique($values));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $sensitiveKeys
     * @param  list<string>  $values
     */
    private function collectSensitivePayloadValues(
        array $payload,
        array $sensitiveKeys,
        array &$values,
        bool $sensitiveContainer = false,
    ): void {
        foreach ($payload as $key => $value) {
            $normalizedKey = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $key) ?? '');
            $isSensitive = $sensitiveContainer || in_array($normalizedKey, $sensitiveKeys, true);

            if (is_array($value)) {
                $this->collectSensitivePayloadValues(
                    $value,
                    $sensitiveKeys,
                    $values,
                    $isSensitive,
                );

                continue;
            }

            if (! $isSensitive || (! is_string($value) && ! is_int($value))) {
                continue;
            }

            $value = trim((string) $value);
            if ($value !== '') {
                $values[] = $value;
            }
        }
    }

    private function isStructuredPayload(string $message): bool
    {
        $message = trim($message);
        if (! str_starts_with($message, '{') && ! str_starts_with($message, '[')) {
            return false;
        }

        json_decode($message, true);

        return json_last_error() === JSON_ERROR_NONE;
    }
}
