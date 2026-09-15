<?php

namespace App\Data;

use App\Enums\IpospaysFeedEventStatus;
use App\Enums\NormalizedTransactionType;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class MappedIpospaysFeedTransaction
{
    public string $providerEventId;

    public string $providerTransactionId;

    public ?string $tpn;

    public ?string $termId;

    public ?string $eventType;

    public ?string $subEventType;

    public ?string $requestType;

    public string $businessAmount;

    public ?string $baseAmount;

    public function __construct(
        string $providerEventId,
        string $providerTransactionId,
        ?string $tpn,
        ?string $termId,
        ?string $eventType,
        ?string $subEventType,
        ?string $requestType,
        public NormalizedTransactionType $transactionType,
        string $businessAmount,
        ?string $baseAmount,
        public CarbonImmutable $occurredAt,
    ) {
        $this->providerEventId = $this->required($providerEventId, 'Provider event ID');
        $this->providerTransactionId = $this->required(
            $providerTransactionId,
            'Provider transaction ID',
        );
        $this->tpn = $this->optional($tpn);
        $this->termId = $this->optional($termId);
        $this->eventType = $this->optional($eventType);
        $this->subEventType = $this->optional($subEventType);
        $this->requestType = $this->optional($requestType);
        $this->businessAmount = $this->required($businessAmount, 'Business amount');
        $this->baseAmount = $this->optional($baseAmount);
    }

    public function payloadFingerprint(): string
    {
        return $this->fingerprint([
            'provider_event_id' => $this->providerEventId,
            'provider_transaction_id' => $this->providerTransactionId,
            'tpn' => $this->tpn,
            'term_id' => $this->termId,
            'event_type' => $this->eventType,
            'sub_event_type' => $this->subEventType,
            'request_type' => $this->requestType,
            'transaction_type' => $this->transactionType->value,
            'business_amount' => $this->businessAmount,
            'base_amount' => $this->baseAmount,
            'occurred_at' => $this->occurredAt->toIso8601String(),
        ]);
    }

    public function financialFingerprint(): string
    {
        return $this->fingerprint([
            'provider_transaction_id' => $this->providerTransactionId,
            'transaction_type' => $this->transactionType->value,
            'business_amount' => $this->businessAmount,
            'base_amount' => $this->baseAmount,
            'occurred_at' => $this->occurredAt->toIso8601String(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
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
            'transaction_type' => $this->transactionType->value,
            'business_amount' => $this->businessAmount,
            'base_amount' => $this->baseAmount,
            'occurred_at' => $this->occurredAt,
            'status' => IpospaysFeedEventStatus::Authenticated->value,
            'payload_fingerprint' => $this->payloadFingerprint(),
            'financial_fingerprint' => $this->financialFingerprint(),
        ];
    }

    private function required(string $value, string $label): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException("{$label} is required.");
        }

        return $value;
    }

    private function optional(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function fingerprint(array $values): string
    {
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }
}
