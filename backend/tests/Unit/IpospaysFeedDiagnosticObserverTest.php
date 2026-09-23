<?php

namespace Tests\Unit;

use App\Services\IpospaysFeedDiagnosticObserver;
use Illuminate\Http\Request;
use Illuminate\Log\LogManager;
use Mockery;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
use Tests\TestCase;

class IpospaysFeedDiagnosticObserverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ipospays.feed.diagnostic_mode', false);
        config()->set('ipospays.feed.max_payload_bytes', 262144);
    }

    public function test_disabled_mode_writes_no_diagnostic_log(): void
    {
        $logs = Mockery::mock(LogManager::class);
        $logs->shouldNotReceive('channel');
        $observer = new IpospaysFeedDiagnosticObserver($logs);

        $observationId = $observer->observe(
            Request::create('/api/webhooks/ipospays/feed', 'POST', content: '{}'),
            '{}',
        );

        $this->assertNull($observationId);
        $observer->recordOutcome(null, 'signature_missing');
    }

    public function test_enabled_mode_logs_structure_without_values_or_sensitive_headers(): void
    {
        config()->set('ipospays.feed.diagnostic_mode', true);
        config()->set('ipospays.feed.hmac_secret', 'HMAC-SECRET-MUST-NOT-APPEAR');
        $logger = new CollectingDiagnosticLogger;
        $observer = $this->observer($logger);
        $rawBody = json_encode([
            'signature' => 'SIGNATURE-VALUE-MUST-NOT-APPEAR',
            'transaction' => [
                'amount' => '987654.32',
                'card' => [
                    'maskedPan' => '411111******1111',
                    'token' => 'CARD-TOKEN-MUST-NOT-APPEAR',
                ],
                'items' => [
                    ['sku' => 'SKU-VALUE-MUST-NOT-APPEAR'],
                ],
            ],
            'customer' => [
                'email' => 'customer-sensitive@example.test',
                'phone' => '+1-555-SENSITIVE',
            ],
        ], JSON_THROW_ON_ERROR);
        $request = Request::create(
            '/api/webhooks/ipospays/feed',
            'POST',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_USER_AGENT' => 'Dejavoo Diagnostic Test/1.0',
                'HTTP_AUTHORIZATION' => 'Bearer AUTHORIZATION-MUST-NOT-APPEAR',
                'HTTP_COOKIE' => 'session=COOKIE-MUST-NOT-APPEAR',
                'HTTP_X_PROVIDER_SIGNATURE' => 'HEADER-SIGNATURE-MUST-NOT-APPEAR',
            ],
            content: $rawBody,
        );

        $observationId = $observer->observe($request, $rawBody);

        $this->assertNotNull($observationId);
        $this->assertCount(1, $logger->records);
        $context = $logger->records[0]['context'];
        $this->assertSame('received', $context['outcome']);
        $this->assertSame('POST', $context['http_method']);
        $this->assertSame('/api/webhooks/ipospays/feed', $context['path']);
        $this->assertSame('application/json', $context['content_type']);
        $this->assertSame(strlen($rawBody), $context['content_length']);
        $this->assertSame('Dejavoo Diagnostic Test/1.0', $context['user_agent']);
        $this->assertTrue($context['json_parse_succeeded']);
        $this->assertTrue($context['signature_present']);
        $this->assertContains('signature', $context['top_level_fields']);
        $this->assertContains('transaction', $context['top_level_fields']);
        $this->assertContains('customer', $context['top_level_fields']);
        $this->assertContains('transaction.amount', $context['key_paths']);
        $this->assertContains('transaction.card.maskedPan', $context['key_paths']);
        $this->assertContains('transaction.items[].sku', $context['key_paths']);
        $this->assertContains('customer.email', $context['key_paths']);
        $this->assertContains('authorization', $context['header_names']);
        $this->assertContains('x-provider-signature', $context['header_names']);

        $logged = json_encode($logger->records, JSON_THROW_ON_ERROR);
        foreach ([
            $rawBody,
            'HMAC-SECRET-MUST-NOT-APPEAR',
            'SIGNATURE-VALUE-MUST-NOT-APPEAR',
            'AUTHORIZATION-MUST-NOT-APPEAR',
            'COOKIE-MUST-NOT-APPEAR',
            'HEADER-SIGNATURE-MUST-NOT-APPEAR',
            '987654.32',
            '411111******1111',
            'CARD-TOKEN-MUST-NOT-APPEAR',
            'SKU-VALUE-MUST-NOT-APPEAR',
            'customer-sensitive@example.test',
            '+1-555-SENSITIVE',
        ] as $prohibitedValue) {
            $this->assertStringNotContainsString($prohibitedValue, $logged);
        }
    }

    public function test_malformed_json_is_observed_without_throwing_or_logging_body_values(): void
    {
        config()->set('ipospays.feed.diagnostic_mode', true);
        $logger = new CollectingDiagnosticLogger;
        $observer = $this->observer($logger);
        $rawBody = '{"field":"MALFORMED-VALUE-MUST-NOT-APPEAR"';

        $observationId = $observer->observe(
            Request::create(
                '/api/webhooks/ipospays/feed',
                'POST',
                server: ['CONTENT_TYPE' => 'application/json'],
                content: $rawBody,
            ),
            $rawBody,
        );

        $this->assertNotNull($observationId);
        $context = $logger->records[0]['context'];
        $this->assertFalse($context['json_parse_succeeded']);
        $this->assertSame('malformed_json', $context['json_inspection']);
        $this->assertSame([], $context['top_level_fields']);
        $this->assertStringNotContainsString(
            'MALFORMED-VALUE-MUST-NOT-APPEAR',
            json_encode($logger->records, JSON_THROW_ON_ERROR),
        );
    }

    public function test_key_discovery_and_oversized_payload_inspection_are_bounded(): void
    {
        config()->set('ipospays.feed.diagnostic_mode', true);
        $logger = new CollectingDiagnosticLogger;
        $observer = $this->observer($logger);
        $manyFields = [];
        for ($index = 0; $index < 250; $index++) {
            $manyFields['field_'.$index] = 'VALUE-'.$index;
        }
        $manyFieldsBody = json_encode($manyFields, JSON_THROW_ON_ERROR);

        $observer->observe(
            Request::create('/api/webhooks/ipospays/feed', 'POST', content: $manyFieldsBody),
            $manyFieldsBody,
        );

        $boundedContext = $logger->records[0]['context'];
        $this->assertLessThanOrEqual(100, count($boundedContext['top_level_fields']));
        $this->assertLessThanOrEqual(100, count($boundedContext['key_paths']));
        $this->assertTrue($boundedContext['keys_truncated']);

        config()->set('ipospays.feed.max_payload_bytes', 32);
        $oversizedBody = json_encode([
            'sensitive' => str_repeat('OVERSIZED-VALUE-MUST-NOT-APPEAR', 10),
        ], JSON_THROW_ON_ERROR);
        $observer->observe(
            Request::create('/api/webhooks/ipospays/feed', 'POST', content: $oversizedBody),
            $oversizedBody,
        );

        $oversizedContext = $logger->records[1]['context'];
        $this->assertNull($oversizedContext['json_parse_succeeded']);
        $this->assertSame('skipped_payload_too_large', $oversizedContext['json_inspection']);
        $this->assertSame([], $oversizedContext['top_level_fields']);
        $this->assertSame([], $oversizedContext['key_paths']);
        $this->assertStringNotContainsString(
            'OVERSIZED-VALUE-MUST-NOT-APPEAR',
            json_encode($logger->records, JSON_THROW_ON_ERROR),
        );
    }

    public function test_logging_failure_never_escapes_the_observer(): void
    {
        config()->set('ipospays.feed.diagnostic_mode', true);
        $logs = Mockery::mock(LogManager::class);
        $logs->shouldReceive('channel')->andThrow(new RuntimeException('log unavailable'));
        $observer = new IpospaysFeedDiagnosticObserver($logs);

        $this->assertNull($observer->observe(
            Request::create('/api/webhooks/ipospays/feed', 'POST', content: '{}'),
            '{}',
        ));

        $observer->recordOutcome('unlogged-observation', 'signature_missing');
        $this->addToAssertionCount(1);
    }

    private function observer(CollectingDiagnosticLogger $logger): IpospaysFeedDiagnosticObserver
    {
        $logs = Mockery::mock(LogManager::class);
        $logs->shouldReceive('channel')
            ->with('ipospays_feed')
            ->andReturn($logger);

        return new IpospaysFeedDiagnosticObserver($logs);
    }
}

class CollectingDiagnosticLogger extends AbstractLogger
{
    /** @var array<int, array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @param  array<string, mixed>  $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
