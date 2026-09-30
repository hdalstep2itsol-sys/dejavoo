<?php

namespace App\Services;

use App\Contracts\IpospaysFeedHmacProfile;
use InvalidArgumentException;

class DejavooFeedV1HmacProfile implements IpospaysFeedHmacProfile
{
    public const NAME = 'dejavoo_feed_v1';

    /** @var array<int, string> */
    private const DATA_FIELDS = [
        'additionalDetails',
        'amount',
        'approvalCode',
        'baseAmount',
        'buildNumber',
        'cardCategory',
        'cardInfo',
        'cardLabel',
        'cardToken',
        'cardType',
        'chName',
        'commercialTaxAmount',
        'commMedia',
        'consumerId',
        'dba',
        'description',
        'destType',
        'deviceModel',
        'email',
        'externalReferenceId',
        'hostResponseCode',
        'hostResponseDate',
        'hostTxId',
        'invoiceNumber',
        'l2L3uploadflag',
        'localTaxAmount',
        'maskedPan',
        'mid',
        'minorChannel',
        'originalRRN',
        'performedBy',
        'phoneNumber',
        'posEntryMode',
        'posMode',
        'posRequestDate',
        'posRequestTime',
        'reconId',
        'responseText',
        'rrn',
        'sourceType',
        'stateTaxAmount',
        'subscriptionId',
        'tagLabel',
        'tagValue',
        'termId',
        'tipAdjAmount',
        'tipAmount',
        'totalFee',
        'tpn',
        'tpnBatchNumber',
        'tpnLabel',
        'transactionId',
        'transactionType',
        'trueCashDiscountFee',
        'txDate',
        'txDuration',
        'txName',
        'txTime',
    ];

    /** @var array<int, string> */
    private const NUMERIC_DATA_FIELDS = [
        'amount',
        'baseAmount',
        'commercialTaxAmount',
        'localTaxAmount',
        'stateTaxAmount',
        'tipAdjAmount',
        'tipAmount',
        'totalFee',
        'trueCashDiscountFee',
    ];

    public function name(): string
    {
        return self::NAME;
    }

    public function expectedSignature(array $payload, string $secret): string
    {
        return hash_hmac('sha512', $this->canonicalize($payload), $secret);
    }

    /**
     * Apply the provider's documented transaction FEED value order. The
     * top-level signature is intentionally never read.
     *
     * @param  array<string, mixed>  $payload
     */
    private function canonicalize(array $payload): string
    {
        $data = $payload['data'] ?? [];
        if (! is_array($data)) {
            throw new InvalidArgumentException('The iPOSpays FEED data field must be an object.');
        }

        $dataValues = [];
        foreach (self::DATA_FIELDS as $field) {
            $dataValues[] = in_array($field, self::NUMERIC_DATA_FIELDS, true)
                ? $this->formatNumber($data[$field] ?? 0)
                : $this->formatValue($data[$field] ?? '');
        }

        return implode('|', [
            $this->formatValue($payload['createdDt'] ?? ''),
            implode('|', $dataValues),
            $this->formatValue($payload['eventType'] ?? ''),
            $this->formatValue($payload['id'] ?? ''),
            $this->formatValue($payload['requestType'] ?? ''),
            $this->formatValue($payload['subEventType'] ?? ''),
            $this->formatValue($payload['version'] ?? ''),
        ]);
    }

    private function formatNumber(mixed $value): string
    {
        if ($value === null) {
            $value = 0;
        }

        if (is_numeric($value)) {
            $number = (float) $value;

            return fmod($number, 1.0) === 0.0
                ? number_format($number, 1, '.', '')
                : (string) $value;
        }

        return $this->formatValue($value);
    }

    private function formatValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_string($value) || is_int($value) || is_float($value)) {
            return (string) $value;
        }

        throw new InvalidArgumentException('An iPOSpays FEED canonical field has an invalid type.');
    }
}
