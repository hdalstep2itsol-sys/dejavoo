<?php

namespace Tests\Unit;

use App\Data\IpospaysFeedBasicAuthResult;
use App\Data\IpospaysFeedHmacResult;
use App\Enums\IpospaysFeedBasicAuthStatus;
use App\Enums\IpospaysFeedHmacStatus;
use App\Services\IpospaysFeedAuthDiagnosticLogger;
use Illuminate\Http\Request;
use Illuminate\Log\LogManager;
use Mockery;
use Tests\TestCase;

class IpospaysFeedAuthDiagnosticLoggerTest extends TestCase
{
    public function test_structured_diagnostic_contains_safe_hashes_and_no_sensitive_values(): void
    {
        config()->set('ipospays.feed.auth_diagnostics', true);
        config()->set('ipospays.feed.hmac_profile', 'dejavoo_feed_v1');
        config()->set('ipospays.feed.hmac_secret', 'PRODUCTION-SECRET-MUST-NEVER-APPEAR');

        $payload = [
            'signature' => 'FULL-SIGNATURE-MUST-NEVER-APPEAR',
            'id' => 'event-123',
            'eventType' => 'Transaction',
            'subEventType' => 'SALE',
            'requestType' => 'N',
            'data' => [
                'tpn' => 'safe-tpn',
                'termId' => 'safe-term-id',
                'maskedPan' => '411111******1111',
                'cardToken' => 'SECRET-CARD-TOKEN',
                'chName' => 'Sensitive Cardholder',
                'email' => 'sensitive@example.test',
                'phoneNumber' => '+15551234567',
                'invoiceNumber' => 'SENSITIVE-INVOICE',
                'amount' => '999.99',
            ],
        ];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $request = Request::create(
            '/api/webhooks/ipospays/feed',
            'POST',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'CONTENT_LENGTH' => (string) strlen($body),
                'REMOTE_ADDR' => '203.0.113.10',
                'HTTP_USER_AGENT' => 'Dejavoo FEED/1.0',
            ],
            content: $body,
        );
        $result = new IpospaysFeedHmacResult(
            IpospaysFeedHmacStatus::InvalidSignature,
            profileName: 'dejavoo_feed_v1',
            canonicalLength: 472,
            canonicalSha256: str_repeat('a', 64),
            expectedSignatureFingerprint: '0123456789abcdef',
            receivedSignatureFingerprint: 'fedcba9876543210',
            signaturesMatched: false,
        );

        $captured = null;
        $channel = Mockery::mock();
        $channel->shouldReceive('info')
            ->once()
            ->with('iPOSpays FEED auth diagnostic.', Mockery::on(
                function (array $context) use (&$captured): bool {
                    $captured = $context;

                    return true;
                },
            ));
        $logs = Mockery::mock(LogManager::class);
        $logs->shouldReceive('channel')->once()->with('ipospays_feed')->andReturn($channel);

        (new IpospaysFeedAuthDiagnosticLogger($logs))->record(
            $request,
            $body,
            'request-123',
            'hmac_signature_invalid',
            $payload,
            $result,
        );

        $this->assertIsArray($captured);
        $this->assertSame('request-123', $captured['request_id']);
        $this->assertSame('hmac_signature_invalid', $captured['stage']);
        $this->assertSame(strlen($body), $captured['body_length']);
        $this->assertSame(hash('sha256', $body), $captured['body_sha256']);
        $this->assertSame(472, $captured['canonical_length']);
        $this->assertSame(str_repeat('a', 64), $captured['canonical_sha256']);
        $this->assertSame('0123456789abcdef', $captured['expected_signature_fp']);
        $this->assertSame('fedcba9876543210', $captured['received_signature_fp']);
        $this->assertFalse($captured['signatures_matched']);
        $this->assertSame('event-123', $captured['provider_event_id']);
        $this->assertSame('safe-tpn', $captured['tpn']);
        $this->assertSame('safe-term-id', $captured['term_id']);

        $logged = json_encode($captured, JSON_THROW_ON_ERROR);
        foreach ([
            'PRODUCTION-SECRET-MUST-NEVER-APPEAR',
            'FULL-SIGNATURE-MUST-NEVER-APPEAR',
            $body,
            '411111******1111',
            'SECRET-CARD-TOKEN',
            'Sensitive Cardholder',
            'sensitive@example.test',
            '+15551234567',
            'SENSITIVE-INVOICE',
            '999.99',
        ] as $sensitiveValue) {
            $this->assertStringNotContainsString($sensitiveValue, $logged);
        }
    }

    public function test_plain_text_diagnostic_contains_only_transport_fingerprints_and_reason(): void
    {
        config()->set('ipospays.feed.auth_diagnostics', true);
        $body = 'This is a test from Host Merchant Services';
        $request = Request::create(
            '/api/webhooks/ipospays/feed',
            'POST',
            server: [
                'CONTENT_TYPE' => 'text/plain',
                'CONTENT_LENGTH' => (string) strlen($body),
                'REMOTE_ADDR' => '203.0.113.10',
                'HTTP_USER_AGENT' => 'Sensitive user agent',
            ],
            content: $body,
        );

        $captured = null;
        $channel = Mockery::mock();
        $channel->shouldReceive('info')->once()->withArgs(
            function (string $message, array $context) use (&$captured): bool {
                $captured = $context;

                return $message === 'iPOSpays FEED auth diagnostic.';
            },
        );
        $logs = Mockery::mock(LogManager::class);
        $logs->shouldReceive('channel')->once()->andReturn($channel);

        (new IpospaysFeedAuthDiagnosticLogger($logs))->record(
            $request,
            $body,
            'request-plain-text',
            'malformed_json',
        );

        $this->assertSame([
            'recorded_at',
            'request_id',
            'stage',
            'content_type',
            'content_length',
            'body_length',
            'body_sha256',
            'json_decode_succeeded',
        ], array_keys($captured));
        $this->assertSame(hash('sha256', $body), $captured['body_sha256']);
        $this->assertStringNotContainsString($body, json_encode($captured, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('Sensitive user agent', json_encode($captured, JSON_THROW_ON_ERROR));
    }

    public function test_basic_auth_diagnostic_logs_only_safe_presence_flags(): void
    {
        config()->set('ipospays.feed.auth_diagnostics', true);
        config()->set('ipospays.feed.basic_username', 'BASIC-USERNAME-MUST-NOT-APPEAR');
        config()->set('ipospays.feed.basic_password', 'BASIC-PASSWORD-MUST-NOT-APPEAR');
        $body = '{"id":"event-123"}';
        $request = Request::create(
            '/api/webhooks/ipospays/feed',
            'POST',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Basic '.base64_encode(
                    'BASIC-USERNAME-MUST-NOT-APPEAR:BASIC-PASSWORD-MUST-NOT-APPEAR',
                ),
            ],
            content: $body,
        );
        $basicResult = new IpospaysFeedBasicAuthResult(
            IpospaysFeedBasicAuthStatus::Verified,
            headerPresent: true,
            usernamePresent: true,
        );

        $captured = null;
        $channel = Mockery::mock();
        $channel->shouldReceive('info')->once()->withArgs(
            function (string $message, array $context) use (&$captured): bool {
                $captured = $context;

                return $message === 'iPOSpays FEED auth diagnostic.';
            },
        );
        $logs = Mockery::mock(LogManager::class);
        $logs->shouldReceive('channel')->once()->andReturn($channel);

        (new IpospaysFeedAuthDiagnosticLogger($logs))->record(
            $request,
            $body,
            'request-basic',
            'basic_auth_verified',
            ['id' => 'event-123'],
            basicResult: $basicResult,
            authMode: 'basic',
        );

        $this->assertSame('basic', $captured['auth_mode']);
        $this->assertTrue($captured['basic_auth_header_present']);
        $this->assertTrue($captured['basic_auth_username_present']);
        $logged = json_encode($captured, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('BASIC-USERNAME-MUST-NOT-APPEAR', $logged);
        $this->assertStringNotContainsString('BASIC-PASSWORD-MUST-NOT-APPEAR', $logged);
        $this->assertStringNotContainsString('Authorization', $logged);
    }
}
