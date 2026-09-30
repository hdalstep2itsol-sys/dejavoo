<?php

namespace App\Data;

use App\Enums\IpospaysFeedEventStatus;
use InvalidArgumentException;

final readonly class MappedIpospaysFeedUnsupportedEvent
{
    public string $providerEventId;

    public ?string $providerTransactionId;

    public ?string $eventType;

    public ?string $subEventType;

    public ?string $requestType;

    public ?string $tpn;

    public ?string $termId;

    public function __construct(
        string $providerEventId,
        ?string $providerTransactionId,
        ?string $eventType,
        ?string $subEventType,
        ?string $requestType,
        ?string $tpn,
        ?string $termId,
        public string $errorCode,
    ) {
        $this->providerEventId = trim($providerEventId);
        if ($this->providerEventId === '') {
            throw new InvalidArgumentException('Provider event ID is required.');
        }

        $this->providerTransactionId = $this->optional($providerTransactionId);
        $this->eventType = $this->optional($eventType);
        $this->subEventType = $this->optional($subEventType);
        $this->requestType = $this->optional($requestType);
        $this->tpn = $this->optional($tpn);
        $this->termId = $this->optional($termId);
    }

    public function payloadFingerprint(): string
    {
        return hash('sha256', json_encode([
            'provider_event_id' => $this->providerEventId,
            'provider_transaction_id' => $this->providerTransactionId,
            'event_type' => $this->eventType,
            'sub_event_type' => $this->subEventType,
            'request_type' => $this->requestType,
            'tpn' => $this->tpn,
            'term_id' => $this->termId,
            'error_code' => $this->errorCode,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    public function receiptAttributes(): array
    {
        return [
            'provider_event_id' => $this->providerEventId,
            'provider_transaction_id' => $this->providerTransactionId,
            'event_type' => $this->eventType,
            'sub_event_type' => $this->subEventType,
            'request_type' => $this->requestType,
            'tpn' => $this->tpn,
            'term_id' => $this->termId,
            'status' => IpospaysFeedEventStatus::Unsupported->value,
            'error_code' => $this->errorCode,
            'payload_fingerprint' => $this->payloadFingerprint(),
        ];
    }

    private function optional(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
