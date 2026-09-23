<?php

namespace Tests\Feature;

use App\Data\MappedIpospaysFeedTransaction;
use App\Enums\IpospaysFeedEventStatus;
use App\Enums\LocationRouteType;
use App\Enums\NormalizedTransactionType;
use App\Enums\TrailerLoadStatus;
use App\Enums\UserRole;
use App\Models\DejavooTerminal;
use App\Models\Location;
use App\Models\NormalizedTransaction;
use App\Models\TrailerLoad;
use App\Models\User;
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

    public function test_duplicate_key_race_re_reads_the_winning_normalized_transaction(): void
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
