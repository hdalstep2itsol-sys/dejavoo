<?php

namespace App\Services;

use App\Data\MappedIpospaysFeedTransaction;
use App\Data\MappedIpospaysFeedUnsupportedEvent;
use App\Enums\NormalizedTransactionType;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

class DejavooFeedV1PayloadMapper
{
    public const NAME = 'dejavoo_feed_v1';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function map(array $payload): MappedIpospaysFeedTransaction|MappedIpospaysFeedUnsupportedEvent
    {
        $eventId = $this->requiredString($payload, 'id', 191);
        $eventType = $this->optionalString($payload, 'eventType', 64);
        $subEventType = $this->optionalString($payload, 'subEventType', 64);
        $requestType = $this->optionalString($payload, 'requestType', 64);
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        if ($eventType !== 'Transaction') {
            return $this->unsupported(
                $eventId,
                $eventType,
                $subEventType,
                $requestType,
                $data,
                'unsupported_event_type',
            );
        }

        $transactionType = match ($subEventType) {
            'SALE' => NormalizedTransactionType::Sale,
            'REFUND' => NormalizedTransactionType::Refund,
            'VOID SALE', 'VOID' => NormalizedTransactionType::Void,
            default => null,
        };

        if ($transactionType === null) {
            return $this->unsupported(
                $eventId,
                $eventType,
                $subEventType,
                $requestType,
                $data,
                'unsupported_transaction_type',
            );
        }

        if (! is_array($payload['data'] ?? null)) {
            throw new InvalidArgumentException('The iPOSpays FEED data field must be an object.');
        }

        return new MappedIpospaysFeedTransaction(
            providerEventId: $eventId,
            providerTransactionId: $this->requiredString($data, 'transactionId', 191),
            tpn: $this->optionalString($data, 'tpn', 64),
            termId: $this->optionalString($data, 'termId', 64),
            eventType: $eventType,
            subEventType: $subEventType,
            requestType: $requestType,
            transactionType: $transactionType,
            businessAmount: $this->decimal($data['amount'] ?? null, 'data.amount'),
            baseAmount: array_key_exists('baseAmount', $data)
                ? $this->decimal($data['baseAmount'], 'data.baseAmount', true)
                : null,
            occurredAt: $this->occurredAt($data),
        );
    }

    /** @param array<string, mixed> $data */
    private function unsupported(
        string $eventId,
        ?string $eventType,
        ?string $subEventType,
        ?string $requestType,
        array $data,
        string $errorCode,
    ): MappedIpospaysFeedUnsupportedEvent {
        return new MappedIpospaysFeedUnsupportedEvent(
            providerEventId: $eventId,
            providerTransactionId: $this->optionalString($data, 'transactionId', 191),
            eventType: $eventType,
            subEventType: $subEventType,
            requestType: $requestType,
            tpn: $this->optionalString($data, 'tpn', 64),
            termId: $this->optionalString($data, 'termId', 64),
            errorCode: $errorCode,
        );
    }

    /** @param array<string, mixed> $values */
    private function occurredAt(array $values): CarbonImmutable
    {
        $date = $this->requiredString($values, 'txDate', 10);
        $time = $this->requiredString($values, 'txTime', 8);
        $timezone = new DateTimeZone((string) config('ipospays.feed.timezone'));
        $local = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            "{$date} {$time}",
            $timezone,
        );
        $errors = DateTimeImmutable::getLastErrors();

        if (! $local
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $local->format('Y-m-d H:i:s') !== "{$date} {$time}") {
            throw new InvalidArgumentException('data.txDate and data.txTime are invalid.');
        }

        return CarbonImmutable::instance($local)->utc();
    }

    private function decimal(mixed $value, string $field, bool $nullable = false): ?string
    {
        if ($nullable && ($value === null || $value === '')) {
            return null;
        }

        if (is_int($value)) {
            $formatted = (string) $value;
        } elseif (is_float($value) && is_finite($value)) {
            $formatted = rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.');
        } elseif (is_string($value)) {
            $formatted = trim($value);
        } else {
            throw new InvalidArgumentException("{$field} must be a decimal value.");
        }

        if (! preg_match('/^\d{1,13}(?:\.\d{1,2})?$/', $formatted)) {
            throw new InvalidArgumentException(
                "{$field} must be a non-negative decimal with at most two decimal places.",
            );
        }

        [$whole, $fraction] = array_pad(explode('.', $formatted, 2), 2, '');

        return $whole.'.'.str_pad($fraction, 2, '0');
    }

    /** @param array<string, mixed> $values */
    private function requiredString(array $values, string $field, int $maxLength): string
    {
        $value = $this->optionalString($values, $field, $maxLength);
        if ($value === null) {
            throw new InvalidArgumentException("{$field} is required.");
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private function optionalString(array $values, string $field, int $maxLength): ?string
    {
        $value = $values[$field] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) && ! is_int($value)) {
            throw new InvalidArgumentException("{$field} must be a string.");
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException("{$field} is too long.");
        }

        return $value;
    }
}
