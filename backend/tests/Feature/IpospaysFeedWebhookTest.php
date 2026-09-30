<?php

namespace Tests\Feature;

use App\Data\MappedIpospaysFeedTransaction;
use App\Enums\IpospaysFeedEventStatus;
use App\Enums\LocationRouteType;
use App\Enums\NormalizedTransactionType;
use App\Enums\TrailerLoadStatus;
use App\Enums\UserRole;
use App\Models\DejavooTerminal;
use App\Models\IpospaysFeedEvent;
use App\Models\Location;
use App\Models\NormalizedTransaction;
use App\Models\TrailerLoad;
use App\Models\User;
use App\Services\DejavooFeedV1HmacProfile;
use App\Services\IpospaysFeedDiagnosticObserver;
use App\Services\IpospaysFeedProcessor;
use App\Services\IpospaysTerminalResolver;
use App\Services\LocationPriceService;
use App\Services\NormalizedTransactionService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PDOException;
use Tests\TestCase;

class IpospaysFeedWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-15 00:00:00');
        config()->set('ipospays.feed.enabled', false);
        config()->set('ipospays.feed.diagnostic_mode', false);
        config()->set('ipospays.feed.hmac_secret');
        config()->set('ipospays.feed.hmac_profile', 'unfinalized');
        config()->set('ipospays.feed.mapping_profile', 'unfinalized');
        config()->set('ipospays.feed.max_payload_bytes', 262144);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_endpoint_exists_outside_sanctum_and_rejects_unsigned_requests(): void
    {
        $this->postRaw('{}')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'hmac_signature_missing');
    }

    public function test_missing_secret_fails_closed_without_processing(): void
    {
        config()->set('ipospays.feed.enabled', true);

        $this->postRaw('{"signature":"provider-signature"}')
            ->assertServiceUnavailable()
            ->assertJsonPath('code', 'hmac_secret_missing');

        $this->assertDatabaseCount('ipospays_feed_events', 0);
        $this->assertDatabaseCount('normalized_transactions', 0);
    }

    public function test_unfinalized_hmac_profile_fails_closed_without_processing(): void
    {
        config()->set('ipospays.feed.enabled', true);
        config()->set('ipospays.feed.hmac_secret', 'test-secret-that-is-never-logged');

        $this->postRaw('{"signature":"provider-signature"}')
            ->assertServiceUnavailable()
            ->assertJsonPath('code', 'hmac_profile_unfinalized');

        $this->assertDatabaseCount('ipospays_feed_events', 0);
        $this->assertDatabaseCount('normalized_transactions', 0);
    }

    public function test_valid_finalized_hmac_passes_authentication_but_mapping_remains_locked(): void
    {
        config()->set('ipospays.feed.enabled', true);
        config()->set('ipospays.feed.hmac_secret', 'unit-test-secret');
        config()->set('ipospays.feed.hmac_profile', DejavooFeedV1HmacProfile::NAME);
        $payload = $this->confirmedProviderPayload();
        $payload['signature'] = app(DejavooFeedV1HmacProfile::class)
            ->expectedSignature($payload, 'unit-test-secret');

        $this->postRaw(json_encode($payload, JSON_THROW_ON_ERROR))
            ->assertServiceUnavailable()
            ->assertJsonPath('code', 'provider_mapping_unfinalized');

        $this->assertDatabaseHas('ipospays_feed_events', [
            'status' => IpospaysFeedEventStatus::PendingProviderMapping->value,
            'error_code' => 'provider_mapping_unfinalized',
        ]);
        $this->assertDatabaseCount('normalized_transactions', 0);
    }

    public function test_one_character_hmac_change_is_rejected_before_mapping(): void
    {
        config()->set('ipospays.feed.enabled', true);
        config()->set('ipospays.feed.hmac_secret', 'unit-test-secret');
        config()->set('ipospays.feed.hmac_profile', DejavooFeedV1HmacProfile::NAME);
        $payload = $this->confirmedProviderPayload();
        $payload['signature'] = app(DejavooFeedV1HmacProfile::class)
            ->expectedSignature($payload, 'unit-test-secret');
        $payload['signature'][0] = $payload['signature'][0] === 'a' ? 'b' : 'a';

        $this->postRaw(json_encode($payload, JSON_THROW_ON_ERROR))
            ->assertUnauthorized()
            ->assertJsonPath('code', 'hmac_signature_invalid');

        $this->assertDatabaseCount('ipospays_feed_events', 0);
        $this->assertDatabaseCount('normalized_transactions', 0);
    }

    public function test_diagnostic_observation_happens_before_unsigned_request_is_rejected(): void
    {
        config()->set('ipospays.feed.diagnostic_mode', true);
        $diagnostics = Mockery::mock(IpospaysFeedDiagnosticObserver::class);
        $diagnostics->shouldReceive('observe')
            ->once()
            ->withArgs(fn ($request, string $rawBody): bool => $rawBody === '{}')
            ->andReturn('observation-1');
        $diagnostics->shouldReceive('recordOutcome')
            ->once()
            ->with('observation-1', 'signature_missing');
        $this->app->instance(IpospaysFeedDiagnosticObserver::class, $diagnostics);

        $this->postRaw('{}')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'hmac_signature_missing');

        $this->assertDatabaseCount('ipospays_feed_events', 0);
        $this->assertDatabaseCount('normalized_transactions', 0);
    }

    public function test_diagnostic_mode_does_not_bypass_unfinalized_profile_or_change_units(): void
    {
        config()->set('ipospays.feed.diagnostic_mode', true);
        config()->set('ipospays.feed.enabled', true);
        config()->set('ipospays.feed.hmac_secret', 'test-secret-that-is-never-logged');
        [$location] = $this->operationalTerminal(['tpn' => 'TPN-DIAGNOSTIC']);
        $load = $this->activeLoad($location);
        $diagnostics = Mockery::mock(IpospaysFeedDiagnosticObserver::class);
        $diagnostics->shouldReceive('observe')->once()->andReturn('observation-2');
        $diagnostics->shouldReceive('recordOutcome')
            ->once()
            ->with('observation-2', 'provider_profile_unfinalized');
        $this->app->instance(IpospaysFeedDiagnosticObserver::class, $diagnostics);

        $this->postRaw(json_encode([
            'signature' => 'provider-signature',
            'tpn' => 'TPN-DIAGNOSTIC',
            'amount' => '20.00',
        ], JSON_THROW_ON_ERROR))
            ->assertServiceUnavailable()
            ->assertJsonPath('code', 'hmac_profile_unfinalized');

        $this->assertDatabaseCount('ipospays_feed_events', 0);
        $this->assertDatabaseCount('normalized_transactions', 0);
        $this->assertSame('0.00000000', $load->refresh()->operationalUnits());
    }

    public function test_malformed_json_is_rejected(): void
    {
        $this->postRaw('{')
            ->assertBadRequest()
            ->assertJsonPath('code', 'malformed_json');
    }

    public function test_payload_size_is_guarded_before_authentication(): void
    {
        config()->set('ipospays.feed.max_payload_bytes', 16);

        $this->postRaw('{"signature":"this-is-too-large"}')
            ->assertStatus(413)
            ->assertJsonPath('code', 'payload_too_large');
    }

    public function test_authentication_rejection_logs_no_secret_signature_or_payload_data(): void
    {
        Log::spy();
        config()->set('ipospays.feed.enabled', true);
        config()->set('ipospays.feed.hmac_secret', 'SECRET-MUST-NOT-APPEAR');

        $this->postRaw(json_encode([
            'signature' => 'SIGNATURE-MUST-NOT-APPEAR',
            'maskedPan' => '411111******1111',
        ], JSON_THROW_ON_ERROR))->assertServiceUnavailable();

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                $logged = json_encode([$message, $context], JSON_THROW_ON_ERROR);

                return ($context['code'] ?? null) === 'hmac_profile_unfinalized'
                    && ! str_contains($logged, 'SECRET-MUST-NOT-APPEAR')
                    && ! str_contains($logged, 'SIGNATURE-MUST-NOT-APPEAR')
                    && ! str_contains($logged, '411111');
            });
    }

    public function test_tpn_resolves_with_exact_trimmed_matching(): void
    {
        [$location, $terminal] = $this->operationalTerminal(['tpn' => 'TPN-100']);

        $resolved = app(IpospaysTerminalResolver::class)->resolve('  TPN-100  ', null);

        $this->assertTrue($terminal->is($resolved));
        $this->assertSame($location->id, $resolved->location_id);
    }

    public function test_terminal_id_resolves_with_exact_trimmed_matching(): void
    {
        [, $terminal] = $this->operationalTerminal(['term_id' => 'TERM-100']);

        $resolved = app(IpospaysTerminalResolver::class)->resolve(null, ' TERM-100 ');

        $this->assertTrue($terminal->is($resolved));
    }

    public function test_tpn_and_terminal_id_must_resolve_to_the_same_mapping(): void
    {
        [, $terminal] = $this->operationalTerminal([
            'tpn' => 'TPN-SAME',
            'term_id' => 'TERM-SAME',
        ]);

        $resolved = app(IpospaysTerminalResolver::class)->resolve('TPN-SAME', 'TERM-SAME');

        $this->assertTrue($terminal->is($resolved));
    }

    public function test_conflicting_tpn_and_terminal_id_are_quarantined(): void
    {
        $location = $this->location();
        $this->terminal($location, ['tpn' => 'TPN-A', 'term_id' => 'TERM-A']);
        $this->terminal($location, ['tpn' => 'TPN-B', 'term_id' => 'TERM-B']);

        $result = $this->process($this->mapped(tpn: 'TPN-A', termId: 'TERM-B'));

        $this->assertSame(IpospaysFeedEventStatus::Conflict, $result->status);
        $this->assertSame('terminal_identifier_conflict', $result->event->error_code);
        $this->assertDatabaseCount('normalized_transactions', 0);
    }

    public function test_unknown_terminal_does_not_create_a_normalized_transaction(): void
    {
        $result = $this->process($this->mapped(tpn: 'TPN-UNKNOWN'));

        $this->assertSame(IpospaysFeedEventStatus::TerminalUnknown, $result->status);
        $this->assertDatabaseCount('normalized_transactions', 0);
    }

    public function test_inactive_terminal_does_not_create_a_normalized_transaction(): void
    {
        $location = $this->location();
        $this->terminal($location, ['tpn' => 'TPN-INACTIVE', 'is_active' => false]);

        $result = $this->process($this->mapped(tpn: 'TPN-INACTIVE'));

        $this->assertSame(IpospaysFeedEventStatus::TerminalInactive, $result->status);
        $this->assertDatabaseCount('normalized_transactions', 0);
    }

    public function test_identical_retry_can_process_after_terminal_is_activated(): void
    {
        $location = $this->location();
        $terminal = $this->terminal($location, [
            'tpn' => 'TPN-RETRY',
            'is_active' => false,
        ]);
        $this->activeLoad($location);
        $data = $this->mapped(
            providerEventId: 'EVENT-RETRY',
            providerTransactionId: 'TRANSACTION-RETRY',
            tpn: 'TPN-RETRY',
        );

        $first = $this->process($data);
        $terminal->update(['is_active' => true]);
        $second = $this->process($data);

        $this->assertSame(IpospaysFeedEventStatus::TerminalInactive, $first->status);
        $this->assertSame(IpospaysFeedEventStatus::Processed, $second->status);
        $this->assertSame(2, $second->event->delivery_count);
        $this->assertDatabaseCount('normalized_transactions', 1);
    }

    public function test_inactive_location_does_not_create_a_normalized_transaction(): void
    {
        $location = $this->location(false);
        $this->terminal($location, ['tpn' => 'TPN-INACTIVE-LOCATION']);

        $result = $this->process($this->mapped(tpn: 'TPN-INACTIVE-LOCATION'));

        $this->assertSame(IpospaysFeedEventStatus::LocationInactive, $result->status);
        $this->assertDatabaseCount('normalized_transactions', 0);
    }

    public function test_processor_stores_only_sanitized_event_fields_and_reuses_normalization(): void
    {
        [$location, $terminal] = $this->operationalTerminal([
            'tpn' => 'TPN-SAFE',
            'term_id' => 'TERM-SAFE',
        ]);
        $this->activeLoad($location);

        $result = $this->process($this->mapped(
            providerEventId: 'EVENT-SAFE',
            providerTransactionId: 'TRANSACTION-SAFE',
            tpn: 'TPN-SAFE',
            termId: 'TERM-SAFE',
        ));

        $this->assertSame(IpospaysFeedEventStatus::Processed, $result->status);
        $this->assertSame($terminal->id, $result->event->dejavoo_terminal_id);
        $this->assertSame($location->id, $result->event->location_id);
        $this->assertSame('1.00000000', $result->normalizedTransaction?->unit_delta);

        $columns = Schema::getColumnListing('ipospays_feed_events');
        foreach ([
            'payload', 'raw_payload', 'signature', 'pan', 'masked_pan', 'card_token',
            'customer_name', 'email', 'phone', 'mid', 'approval_code', 'rrn',
            'cardholder', 'consumer_id', 'subscription_id', 'metadata',
        ] as $sensitiveColumn) {
            $this->assertNotContains($sensitiveColumn, $columns);
        }
    }

    public function test_pending_mapping_receipt_contains_only_internal_processing_metadata(): void
    {
        $event = app(IpospaysFeedProcessor::class)->recordPendingProviderMapping();

        $this->assertSame(IpospaysFeedEventStatus::PendingProviderMapping, $event->status);
        $this->assertSame('provider_mapping_unfinalized', $event->error_code);
        $this->assertNull($event->provider_event_id);
        $this->assertNull($event->provider_transaction_id);
        $this->assertNull($event->payload_fingerprint);
        $this->assertNull($event->tpn);
        $this->assertNull($event->term_id);
        $this->assertNull($event->business_amount);
        $this->assertNull($event->base_amount);
        $this->assertNotNull($event->authenticated_at);
    }

    public function test_duplicate_event_delivery_is_idempotent(): void
    {
        [$location] = $this->operationalTerminal(['tpn' => 'TPN-DUPLICATE-EVENT']);
        $this->activeLoad($location);
        $data = $this->mapped(
            providerEventId: 'EVENT-DUPLICATE',
            providerTransactionId: 'TRANSACTION-DUPLICATE',
            tpn: 'TPN-DUPLICATE-EVENT',
        );

        $first = $this->process($data);
        $second = $this->process($data);

        $this->assertSame(IpospaysFeedEventStatus::Processed, $first->status);
        $this->assertSame(IpospaysFeedEventStatus::Duplicate, $second->status);
        $this->assertDatabaseCount('ipospays_feed_events', 1);
        $this->assertDatabaseCount('normalized_transactions', 1);
        $this->assertSame(2, $second->event->delivery_count);
    }

    public function test_unique_event_id_is_the_database_race_guard(): void
    {
        [$location] = $this->operationalTerminal(['tpn' => 'TPN-EVENT-GUARD']);
        $this->activeLoad($location);
        $event = $this->process($this->mapped(tpn: 'TPN-EVENT-GUARD'))->event;
        $attributes = $event->getAttributes();
        unset($attributes['id']);

        $this->expectException(QueryException::class);

        DB::table('ipospays_feed_events')->insert($attributes);
    }

    public function test_distinct_event_for_same_identical_transaction_is_idempotent(): void
    {
        [$location] = $this->operationalTerminal(['tpn' => 'TPN-DUPLICATE-TX']);
        $this->activeLoad($location);

        $first = $this->process($this->mapped(
            providerEventId: 'EVENT-FIRST',
            providerTransactionId: 'TRANSACTION-ONE',
            tpn: 'TPN-DUPLICATE-TX',
        ));
        $second = $this->process($this->mapped(
            providerEventId: 'EVENT-SECOND',
            providerTransactionId: 'TRANSACTION-ONE',
            tpn: 'TPN-DUPLICATE-TX',
        ));

        $this->assertSame(IpospaysFeedEventStatus::Processed, $first->status);
        $this->assertSame(IpospaysFeedEventStatus::Duplicate, $second->status);
        $this->assertSame($first->normalizedTransaction?->id, $second->normalizedTransaction?->id);
        $this->assertDatabaseCount('normalized_transactions', 1);
    }

    public function test_concurrent_duplicate_postings_re_read_the_winning_normalized_transaction(): void
    {
        [$location] = $this->operationalTerminal(['tpn' => 'TPN-TX-RACE']);
        $this->activeLoad($location);
        $realService = app(NormalizedTransactionService::class);
        $racingService = Mockery::mock(NormalizedTransactionService::class);
        $racingService->shouldReceive('create')
            ->once()
            ->andReturnUsing(function (
                int $locationId,
                NormalizedTransactionType $transactionType,
                string $businessAmount,
                CarbonInterface $occurredAt,
                string $source,
                ?string $externalTransactionId,
                ?int $dejavooTerminalId,
            ) use ($realService): never {
                $realService->create(
                    $locationId,
                    $transactionType,
                    $businessAmount,
                    $occurredAt,
                    $source,
                    $externalTransactionId,
                    $dejavooTerminalId,
                );

                throw new QueryException(
                    'sqlite',
                    'insert into normalized_transactions',
                    [],
                    new PDOException('simulated duplicate-key race'),
                );
            });

        $processor = new IpospaysFeedProcessor(
            app(IpospaysTerminalResolver::class),
            $racingService,
        );
        $result = $processor->process($this->mapped(
            providerEventId: 'EVENT-TX-RACE',
            providerTransactionId: 'TRANSACTION-TX-RACE',
            tpn: 'TPN-TX-RACE',
        ));

        $this->assertSame(IpospaysFeedEventStatus::Duplicate, $result->status);
        $this->assertNotNull($result->normalizedTransaction);
        $this->assertDatabaseCount('normalized_transactions', 1);
    }

    public function test_materially_conflicting_transaction_update_is_quarantined(): void
    {
        [$location] = $this->operationalTerminal(['tpn' => 'TPN-CONFLICT']);
        $this->activeLoad($location);
        $this->process($this->mapped(
            providerEventId: 'EVENT-ORIGINAL',
            providerTransactionId: 'TRANSACTION-CONFLICT',
            tpn: 'TPN-CONFLICT',
            businessAmount: '20.00',
        ));

        $result = $this->process($this->mapped(
            providerEventId: 'EVENT-UPDATE',
            providerTransactionId: 'TRANSACTION-CONFLICT',
            tpn: 'TPN-CONFLICT',
            businessAmount: '25.00',
        ));

        $this->assertSame(IpospaysFeedEventStatus::Conflict, $result->status);
        $this->assertSame('transaction_payload_conflict', $result->event->error_code);
        $this->assertDatabaseCount('normalized_transactions', 1);
        $this->assertSame('20.00', NormalizedTransaction::query()->sole()->business_amount);
    }

    public function test_signed_sale_uses_amount_and_transaction_time_for_normalization(): void
    {
        [$location, $terminal] = $this->operationalTerminal([
            'tpn' => 'observed-tpn',
            'term_id' => 'observed-terminal-id',
        ]);
        $load = $this->activeLoad($location);
        $payload = $this->confirmedProviderPayload();
        $payload['createdDt'] = '2025-01-01 00:00:00';
        $payload['data']['amount'] = 20.0;
        $payload['data']['baseAmount'] = 999.0;

        $this->postSigned($payload)
            ->assertOk()
            ->assertExactJson(['status' => 'accepted', 'code' => 'processed']);

        $transaction = NormalizedTransaction::query()->sole();
        $event = IpospaysFeedEvent::query()
            ->where('normalized_transaction_id', $transaction->id)
            ->sole();
        $this->assertSame('ipospays_feed', $transaction->source);
        $this->assertSame('observed-transaction-id', $transaction->external_transaction_id);
        $this->assertSame(NormalizedTransactionType::Sale, $transaction->transaction_type);
        $this->assertSame('20.00', $transaction->business_amount);
        $this->assertSame('1.00000000', $transaction->unit_delta);
        $this->assertSame('2026-09-30 16:34:56', $transaction->occurred_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('observed-event-id', $event->provider_event_id);
        $this->assertSame('observed-transaction-id', $event->provider_transaction_id);
        $this->assertSame($terminal->id, $transaction->dejavoo_terminal_id);
        $this->assertSame('1.00000000', $load->refresh()->operationalUnits());
    }

    public function test_signed_refund_maps_to_negative_units(): void
    {
        [$location] = $this->operationalTerminal([
            'tpn' => 'observed-tpn',
            'term_id' => 'observed-terminal-id',
        ]);
        $this->activeLoad($location);
        $payload = $this->confirmedProviderPayload();
        $payload['subEventType'] = 'REFUND';

        $this->postSigned($payload)->assertOk();

        $transaction = NormalizedTransaction::query()->sole();
        $this->assertSame(NormalizedTransactionType::Refund, $transaction->transaction_type);
        $this->assertSame('-1.00000000', $transaction->unit_delta);
    }

    public function test_signed_void_sale_and_void_map_to_void(): void
    {
        foreach (['VOID SALE', 'VOID'] as $index => $subEventType) {
            [$location] = $this->operationalTerminal([
                'tpn' => "VOID-TPN-{$index}",
                'term_id' => "VOID-TERM-{$index}",
            ]);
            $this->activeLoad($location);
            $payload = $this->confirmedProviderPayload();
            $payload['id'] = "VOID-EVENT-{$index}";
            $payload['subEventType'] = $subEventType;
            $payload['data']['transactionId'] = "VOID-TRANSACTION-{$index}";
            $payload['data']['tpn'] = "VOID-TPN-{$index}";
            $payload['data']['termId'] = "VOID-TERM-{$index}";

            $this->postSigned($payload)->assertOk();
        }

        $this->assertDatabaseCount('normalized_transactions', 2);
        $this->assertSame(
            [NormalizedTransactionType::Void, NormalizedTransactionType::Void],
            NormalizedTransaction::query()->orderBy('id')->get()
                ->map(fn (NormalizedTransaction $transaction) => $transaction->transaction_type)
                ->all(),
        );
        $this->assertSame(
            ['-1.00000000', '-1.00000000'],
            NormalizedTransaction::query()->orderBy('id')->pluck('unit_delta')->all(),
        );
    }

    public function test_unsupported_transaction_type_is_acknowledged_without_units(): void
    {
        [$location] = $this->operationalTerminal([
            'tpn' => 'observed-tpn',
            'term_id' => 'observed-terminal-id',
        ]);
        $load = $this->activeLoad($location);
        $payload = $this->confirmedProviderPayload();
        $payload['subEventType'] = 'TIP ADJUST';

        $this->postSigned($payload)
            ->assertOk()
            ->assertExactJson(['status' => 'accepted', 'code' => 'unsupported']);

        $this->assertDatabaseHas('ipospays_feed_events', [
            'provider_event_id' => 'observed-event-id',
            'status' => IpospaysFeedEventStatus::Unsupported->value,
            'error_code' => 'unsupported_transaction_type',
        ]);
        $this->assertDatabaseCount('normalized_transactions', 0);
        $this->assertSame('0.00000000', $load->refresh()->operationalUnits());
    }

    public function test_two_provider_postings_create_one_transaction_and_one_unit_delta(): void
    {
        [$location] = $this->operationalTerminal([
            'tpn' => 'observed-tpn',
            'term_id' => 'observed-terminal-id',
        ]);
        $load = $this->activeLoad($location);
        $first = $this->confirmedProviderPayload();
        $second = $first;
        $second['id'] = 'observed-update-event-id';
        $second['requestType'] = 'U';
        $second['data']['baseAmount'] = 777.0;
        $second['data']['approvalCode'] = 'new-receipt-field';

        $this->postSigned($first)->assertOk()->assertJsonPath('code', 'processed');
        $this->postSigned($second)->assertOk()->assertJsonPath('code', 'duplicate');

        $this->assertDatabaseCount('ipospays_feed_events', 2);
        $this->assertDatabaseCount('normalized_transactions', 1);
        $this->assertSame('1.00000000', $load->refresh()->operationalUnits());
        $this->assertSame(
            1,
            NormalizedTransaction::query()
                ->where('source', 'ipospays_feed')
                ->where('external_transaction_id', 'observed-transaction-id')
                ->count(),
        );
    }

    public function test_settlement_event_is_acknowledged_without_normalization(): void
    {
        $payload = $this->confirmedProviderPayload();
        $payload['eventType'] = 'Settlement';
        $payload['subEventType'] = null;

        $this->postSigned($payload)
            ->assertOk()
            ->assertExactJson(['status' => 'accepted', 'code' => 'unsupported']);

        $this->assertDatabaseHas('ipospays_feed_events', [
            'provider_event_id' => 'observed-event-id',
            'status' => IpospaysFeedEventStatus::Unsupported->value,
            'error_code' => 'unsupported_event_type',
        ]);
        $this->assertDatabaseCount('normalized_transactions', 0);
    }

    public function test_signed_transaction_for_unknown_terminal_does_not_normalize(): void
    {
        $payload = $this->confirmedProviderPayload();
        $payload['data']['termId'] = null;

        $this->postSigned($payload)
            ->assertStatus(422)
            ->assertJsonPath('code', 'terminal_unknown');

        $this->assertDatabaseCount('normalized_transactions', 0);
    }

    private function postRaw(string $body)
    {
        return $this->call(
            'POST',
            '/api/webhooks/ipospays/feed',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_ORIGIN' => 'http://localhost:3000',
            ],
            $body,
        );
    }

    /** @param array<string, mixed> $payload */
    private function postSigned(array $payload)
    {
        config()->set('ipospays.feed.enabled', true);
        config()->set('ipospays.feed.hmac_secret', 'unit-test-secret');
        config()->set('ipospays.feed.hmac_profile', DejavooFeedV1HmacProfile::NAME);
        config()->set('ipospays.feed.mapping_profile', 'dejavoo_feed_v1');
        config()->set('ipospays.feed.timezone', 'America/New_York');
        $payload['signature'] = app(DejavooFeedV1HmacProfile::class)
            ->expectedSignature($payload, 'unit-test-secret');

        return $this->postRaw(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function confirmedProviderPayload(): array
    {
        return [
            'signature' => null,
            'id' => 'observed-event-id',
            'eventType' => 'Transaction',
            'subEventType' => 'SALE',
            'requestType' => 'N',
            'version' => '1.0',
            'createdDt' => '2026-09-30 12:34:56',
            'data' => [
                'tpn' => 'observed-tpn',
                'termId' => 'observed-terminal-id',
                'mid' => 'observed-mid',
                'transactionId' => 'observed-transaction-id',
                'amount' => 20.0,
                'baseAmount' => 18.5,
                'transactionType' => 'CREDIT',
                'txDate' => '2026-09-30',
                'txTime' => '12:34:56',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $terminalAttributes
     * @return array{Location, DejavooTerminal}
     */
    private function operationalTerminal(array $terminalAttributes): array
    {
        $location = $this->location();

        return [$location, $this->terminal($location, $terminalAttributes)];
    }

    private function location(bool $active = true): Location
    {
        $location = Location::query()->create([
            'name' => 'FEED Test '.uniqid(),
            'unit_price' => '20.00',
            'haul_threshold' => '70.00',
            'route_type' => LocationRouteType::Open,
            'is_active' => $active,
        ]);
        app(LocationPriceService::class)->ensureHistory($location);

        return $location;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function terminal(Location $location, array $attributes): DejavooTerminal
    {
        return DejavooTerminal::query()->create([
            'location_id' => $location->id,
            'tpn' => null,
            'term_id' => null,
            'is_active' => true,
            ...$attributes,
        ]);
    }

    private function activeLoad(Location $location): TrailerLoad
    {
        $creator = User::factory()->create(['role' => UserRole::OwnerAdmin]);

        return TrailerLoad::query()->create([
            'location_id' => $location->id,
            'status' => TrailerLoadStatus::Active,
            'started_at' => CarbonImmutable::parse('2026-09-15 08:00:00'),
            'created_by_user_id' => $creator->id,
        ]);
    }

    private function mapped(
        string $providerEventId = 'EVENT-1',
        string $providerTransactionId = 'TRANSACTION-1',
        ?string $tpn = null,
        ?string $termId = null,
        string $businessAmount = '20.00',
    ): MappedIpospaysFeedTransaction {
        return new MappedIpospaysFeedTransaction(
            providerEventId: $providerEventId,
            providerTransactionId: $providerTransactionId,
            tpn: $tpn,
            termId: $termId,
            eventType: 'confirmed-internal-event',
            subEventType: null,
            requestType: null,
            transactionType: NormalizedTransactionType::Sale,
            businessAmount: $businessAmount,
            baseAmount: null,
            occurredAt: CarbonImmutable::parse('2026-09-15 09:00:00'),
        );
    }

    private function process(MappedIpospaysFeedTransaction $data)
    {
        return app(IpospaysFeedProcessor::class)->process($data);
    }
}
