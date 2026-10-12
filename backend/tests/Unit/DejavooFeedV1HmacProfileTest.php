<?php

namespace Tests\Unit;

use App\Enums\IpospaysFeedHmacStatus;
use App\Services\DejavooFeedV1HmacProfile;
use App\Services\IpospaysFeedHmacVerifier;
use ReflectionMethod;
use Tests\TestCase;

class DejavooFeedV1HmacProfileTest extends TestCase
{
    public function test_provider_sample_reproduces_the_exact_supplied_canonical_string(): void
    {
        $profile = new DejavooFeedV1HmacProfile;
        $payload = [
            'signature' => 'excluded-provider-signature',
            'id' => '229f78b9-6861-49d3-8fb3-d3282b9ab801',
            'eventType' => 'Transaction',
            'subEventType' => 'SALE',
            'requestType' => 'N',
            'version' => '3.0',
            'createdDt' => '2024-09-03 08:53:39',
            'data' => [
                'additionalDetails' => '',
                'amount' => 3.25,
                'approvalCode' => 'TAS865',
                'baseAmount' => 2.4,
                'buildNumber' => null,
                'cardCategory' => 'CLASSIC',
                'cardInfo' => 'PERSONAL',
                'cardLabel' => 'VISA',
                'cardToken' => null,
                'cardType' => 'CREDIT',
                'chName' => 'Dhoni',
                'commercialTaxAmount' => 0.0,
                'commMedia' => null,
                'consumerId' => null,
                'dba' => 'Virat',
                'description' => 'PreAuthentication',
                'destType' => 'TSYS - QA',
                'deviceModel' => 'Cloud POS',
                'email' => 'femeyey365@seosnaps.com',
                'externalReferenceId' => null,
                'hostResponseCode' => '00',
                'hostResponseDate' => '2024-09-03',
                'hostTxId' => null,
                'invoiceNumber' => '000002',
                'L2l3UploadFlag' => 'provider-alias-not-in-canonical-string',
                'localTaxAmount' => 0.5,
                'maskedPan' => '424242******4242',
                'Mid' => 'provider-alias-not-in-canonical-string',
                'minorChannel' => 'E-Commerce',
                'originalRRN' => null,
                'performedBy' => 'femeyey365@seosnaps.com',
                'phoneNumber' => '+19898989898',
                'posEntryMode' => '100',
                'posMode' => 'COF',
                'posRequestDate' => '2024-09-03',
                'posRequestTime' => '09:35:07',
                'reconId' => null,
                'responseText' => 'NO MATCH',
                'rrn' => '424708751921',
                'sourceType' => 'ECOM',
                'stateTaxAmount' => 0.25,
                'subscriptionId' => null,
                'tagLabel' => null,
                'tagValue' => null,
                'TermId' => 'provider-alias-not-in-canonical-string',
                'tipAdjAmount' => 0.0,
                'tipAmount' => 0.0,
                'totalFee' => 0.0,
                'tpn' => '931626161433',
                'tpnBatchNumber' => '408',
                'tpnLabel' => null,
                'transactionId' => '18041453739238035220240903133507',
                'transactionType' => 'CREDIT',
                'trueCashDiscountFee' => 0.1,
                'txDate' => '2024-09-03',
                'txDuration' => '0.786',
                'txName' => 'SALE',
                'txTime' => '04:05:06',
            ],
        ];
        $expected = '2024-09-03 08:53:39||3.25|TAS865|2.4||CLASSIC|PERSONAL|VISA||CREDIT|Dhoni|0.0|||Virat|PreAuthentication|TSYS - QA|Cloud POS|femeyey365@seosnaps.com||00|2024-09-03||000002||0.5|424242******4242||E-Commerce||femeyey365@seosnaps.com|+19898989898|100|COF|2024-09-03|09:35:07||NO MATCH|424708751921|ECOM|0.25|||||0.0|0.0|0.0|931626161433|408||18041453739238035220240903133507|CREDIT|0.1|2024-09-03|0.786|SALE|04:05:06|Transaction|229f78b9-6861-49d3-8fb3-d3282b9ab801|N|SALE|3.0';

        $this->assertSame($expected, $this->canonicalize($profile, $payload));
        $this->assertSame(472, strlen($expected));
        $this->assertSame(
            'd7db45a799ad668e500aeb2f23ff8c3d3ed5b1ba68b5328a95fea6b1a808e7c9',
            hash('sha256', $expected),
        );
    }

    public function test_profile_uses_the_official_transaction_field_order(): void
    {
        $profile = new DejavooFeedV1HmacProfile;
        $payload = $this->payload([
            'txTime' => 'txTime-value',
            'termId' => 'termId-value',
            'mid' => 'mid-value',
            'amount' => 2.6,
            'baseAmount' => 1.92,
            'tpn' => 'tpn-value',
            'transactionId' => 'transactionId-value',
            'transactionType' => 'transactionType-value',
            'txDate' => 'txDate-value',
        ]);

        $expectedCanonical = $this->referenceCanonicalize($payload);

        $this->assertSame(
            hash_hmac('sha512', $expectedCanonical, 'unit-test-secret'),
            $profile->expectedSignature($payload, 'unit-test-secret'),
        );
        $this->assertSame(128, strlen($profile->expectedSignature($payload, 'unit-test-secret')));
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{128}$/',
            $profile->expectedSignature($payload, 'unit-test-secret'),
        );
    }

    public function test_signature_value_is_excluded_from_canonicalization(): void
    {
        $profile = new DejavooFeedV1HmacProfile;
        $first = $this->payload(['tpn' => 'TPN-1']);
        $second = $first;
        $first['signature'] = 'first-signature-value';
        $second['signature'] = 'completely-different-signature-value';

        $this->assertSame(
            $profile->expectedSignature($first, 'unit-test-secret'),
            $profile->expectedSignature($second, 'unit-test-secret'),
        );
    }

    public function test_confirmed_real_field_casing_is_used_exactly(): void
    {
        $profile = new DejavooFeedV1HmacProfile;
        $confirmed = $this->payload([
            'termId' => 'confirmed-term-id',
            'mid' => 'confirmed-mid',
        ]);
        $incorrectCase = $this->payload([
            'TermId' => 'confirmed-term-id',
            'Mid' => 'confirmed-mid',
        ]);
        $empty = $this->payload();

        $this->assertNotSame(
            $profile->expectedSignature($confirmed, 'unit-test-secret'),
            $profile->expectedSignature($empty, 'unit-test-secret'),
        );
        $this->assertSame(
            $profile->expectedSignature($incorrectCase, 'unit-test-secret'),
            $profile->expectedSignature($empty, 'unit-test-secret'),
        );
    }

    public function test_null_empty_and_numeric_values_follow_the_official_php_rules(): void
    {
        $profile = new DejavooFeedV1HmacProfile;
        $payload = $this->payload([
            'amount' => null,
            'baseAmount' => '',
            'commercialTaxAmount' => 2,
            'localTaxAmount' => 2.5,
            'stateTaxAmount' => '3.00',
            'tipAdjAmount' => '3.25',
            'tipAmount' => 0,
            'trueCashDiscountFee' => 'not-numeric',
        ]);

        $canonical = $this->canonicalize($profile, $payload);
        $values = explode('|', $canonical);

        $this->assertSame('0.0', $values[2]);
        $this->assertSame('', $values[4]);
        $this->assertSame('2.0', $values[12]);
        $this->assertSame('2.5', $values[26]);
        $this->assertSame('3.0', $values[41]);
        $this->assertSame('3.25', $values[46]);
        $this->assertSame('0.0', $values[47]);
        $this->assertSame('0.0', $values[48]);
        $this->assertSame('not-numeric', $values[54]);
    }

    public function test_registered_profile_accepts_valid_hmac_and_rejects_one_character_change(): void
    {
        config()->set('ipospays.feed.enabled', true);
        config()->set('ipospays.feed.hmac_secret', 'unit-test-secret');
        config()->set('ipospays.feed.hmac_profile', DejavooFeedV1HmacProfile::NAME);
        $profile = app(DejavooFeedV1HmacProfile::class);
        $payload = $this->payload(['tpn' => 'TPN-1']);
        $payload['signature'] = $profile->expectedSignature($payload, 'unit-test-secret');

        $valid = app(IpospaysFeedHmacVerifier::class)->verify($payload);
        $payload['signature'][0] = $payload['signature'][0] === 'a' ? 'b' : 'a';
        $invalid = app(IpospaysFeedHmacVerifier::class)->verify($payload);

        $this->assertSame(IpospaysFeedHmacStatus::Verified, $valid->status);
        $this->assertSame(IpospaysFeedHmacStatus::InvalidSignature, $invalid->status);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function payload(array $data = []): array
    {
        return [
            'signature' => null,
            'id' => 'event-id',
            'eventType' => 'Transaction',
            'subEventType' => 'SALE',
            'requestType' => 'N',
            'version' => '1.0',
            'createdDt' => '2026-09-30 12:34:56',
            'data' => $data,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function canonicalize(DejavooFeedV1HmacProfile $profile, array $payload): string
    {
        $method = new ReflectionMethod($profile, 'canonicalize');

        return $method->invoke($profile, $payload);
    }

    /**
     * Independent reference implementation of the provider's published PHP
     * value sequence.
     *
     * @param  array<string, mixed>  $payload
     */
    private function referenceCanonicalize(array $payload): string
    {
        $data = $payload['data'];
        $numeric = fn (string $field): string => $this->referenceNumber($data[$field] ?? 0);
        $text = fn (string $field): string => (string) ($data[$field] ?? '');

        return implode('|', [
            (string) ($payload['createdDt'] ?? ''),
            $text('additionalDetails'),
            $numeric('amount'),
            $text('approvalCode'),
            $numeric('baseAmount'),
            $text('buildNumber'),
            $text('cardCategory'),
            $text('cardInfo'),
            $text('cardLabel'),
            $text('cardToken'),
            $text('cardType'),
            $text('chName'),
            $numeric('commercialTaxAmount'),
            $text('commMedia'),
            $text('consumerId'),
            $text('dba'),
            $text('description'),
            $text('destType'),
            $text('deviceModel'),
            $text('email'),
            $text('externalReferenceId'),
            $text('hostResponseCode'),
            $text('hostResponseDate'),
            $text('hostTxId'),
            $text('invoiceNumber'),
            $text('l2L3uploadflag'),
            $numeric('localTaxAmount'),
            $text('maskedPan'),
            $text('mid'),
            $text('minorChannel'),
            $text('originalRRN'),
            $text('performedBy'),
            $text('phoneNumber'),
            $text('posEntryMode'),
            $text('posMode'),
            $text('posRequestDate'),
            $text('posRequestTime'),
            $text('reconId'),
            $text('responseText'),
            $text('rrn'),
            $text('sourceType'),
            $numeric('stateTaxAmount'),
            $text('subscriptionId'),
            $text('tagLabel'),
            $text('tagValue'),
            $text('termId'),
            $numeric('tipAdjAmount'),
            $numeric('tipAmount'),
            $numeric('totalFee'),
            $text('tpn'),
            $text('tpnBatchNumber'),
            $text('tpnLabel'),
            $text('transactionId'),
            $text('transactionType'),
            $numeric('trueCashDiscountFee'),
            $text('txDate'),
            $text('txDuration'),
            $text('txName'),
            $text('txTime'),
            (string) ($payload['eventType'] ?? ''),
            (string) ($payload['id'] ?? ''),
            (string) ($payload['requestType'] ?? ''),
            (string) ($payload['subEventType'] ?? ''),
            (string) ($payload['version'] ?? ''),
        ]);
    }

    private function referenceNumber(mixed $value): string
    {
        $value ??= 0;
        if (! is_numeric($value)) {
            return (string) $value;
        }

        return fmod((float) $value, 1.0) === 0.0
            ? number_format((float) $value, 1, '.', '')
            : (string) $value;
    }
}
