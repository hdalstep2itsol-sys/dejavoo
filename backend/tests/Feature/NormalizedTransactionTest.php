<?php

namespace Tests\Feature;

use App\Enums\DriverCommitmentSource;
use App\Enums\LocationRouteType;
use App\Enums\NormalizedTransactionType;
use App\Enums\TrailerLoadStatus;
use App\Enums\UserRole;
use App\Exceptions\NormalizedTransactionException;
use App\Models\Location;
use App\Models\NormalizedTransaction;
use App\Models\TrailerLoad;
use App\Models\User;
use App\Services\LocationPriceService;
use App\Services\NormalizedTransactionService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NormalizedTransactionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-09-09 00:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_twenty_dollar_sale_at_twenty_dollar_unit_price_calculates_one_unit(): void
    {
        [$location, $load] = $this->locationAndLoad('20.00');

        $transaction = $this->createTransaction($location, '20.00', NormalizedTransactionType::Sale);

        $this->assertSame($load->id, $transaction->trailer_load_id);
        $this->assertSame('20.00', $transaction->unit_price_snapshot);
        $this->assertSame('1.00000000', $transaction->unit_delta);
    }

    public function test_twenty_five_dollar_sale_and_fractional_sale_are_not_rounded(): void
    {
        [$location] = $this->locationAndLoad('20.00');

        $twentyFive = $this->createTransaction($location, '25.00', NormalizedTransactionType::Sale);
        $fractional = $this->createTransaction($location, '17.80', NormalizedTransactionType::Sale);

        $this->assertSame('1.25000000', $twentyFive->unit_delta);
        $this->assertSame('0.89000000', $fractional->unit_delta);
    }

    public function test_refund_and_void_create_negative_unit_deltas(): void
    {
        [$location] = $this->locationAndLoad('20.00');

        $refund = $this->createTransaction($location, '5.00', NormalizedTransactionType::Refund);
        $void = $this->createTransaction($location, '20.00', NormalizedTransactionType::Void);

        $this->assertSame('-0.25000000', $refund->unit_delta);
        $this->assertSame('-1.00000000', $void->unit_delta);
        $this->assertSame('5.00', $refund->business_amount);
        $this->assertSame('20.00', $void->business_amount);
    }

    public function test_price_snapshot_and_existing_calculation_survive_location_price_change(): void
    {
        [$location] = $this->locationAndLoad('20.00');
        $transaction = $this->createTransaction($location, '17.80', NormalizedTransactionType::Sale);

        $location->update(['unit_price' => '25.00']);
        $transaction->refresh();

        $this->assertSame('20.00', $transaction->unit_price_snapshot);
        $this->assertSame('0.89000000', $transaction->unit_delta);
    }

    public function test_transaction_is_assigned_to_load_covering_its_timestamp(): void
    {
        $location = $this->location('20.00');
        $oldLoad = $this->load(
            $location,
            '2026-09-09 08:00:00',
            '2026-09-09 10:00:00',
            TrailerLoadStatus::PendingWarehouseCount,
        );
        $newLoad = $this->load($location, '2026-09-09 10:00:00');

        $oldTransaction = $this->createTransaction(
            $location,
            '20.00',
            NormalizedTransactionType::Sale,
            '2026-09-09 09:59:59',
        );
        $boundaryTransaction = $this->createTransaction(
            $location,
            '20.00',
            NormalizedTransactionType::Sale,
            '2026-09-09 10:00:00',
        );

        $this->assertSame($oldLoad->id, $oldTransaction->trailer_load_id);
        $this->assertSame($newLoad->id, $boundaryTransaction->trailer_load_id);
    }

    public function test_late_arriving_transaction_after_swap_attaches_to_old_load(): void
    {
        $location = $this->location('20.00');
        $oldLoad = $this->load(
            $location,
            '2026-09-09 08:00:00',
            '2026-09-09 10:00:00',
            TrailerLoadStatus::PendingWarehouseCount,
        );
        $this->load($location, '2026-09-09 10:00:00');

        $transaction = $this->createTransaction(
            $location,
            '17.80',
            NormalizedTransactionType::Sale,
            '2026-09-09 09:00:00',
        );

        $this->assertSame($oldLoad->id, $transaction->trailer_load_id);
    }

    public function test_transaction_before_first_load_is_rejected(): void
    {
        [$location] = $this->locationAndLoad('20.00', '2026-09-09 08:00:00');

        $this->expectException(NormalizedTransactionException::class);
        $this->expectExceptionMessage('No trailer/load cycle covers the transaction timestamp.');

        $this->createTransaction(
            $location,
            '20.00',
            NormalizedTransactionType::Sale,
            '2026-09-09 07:59:59',
        );
    }

    public function test_transaction_in_load_cycle_gap_is_rejected(): void
    {
        $location = $this->location('20.00');
        $this->load(
            $location,
            '2026-09-09 08:00:00',
            '2026-09-09 09:00:00',
            TrailerLoadStatus::PendingWarehouseCount,
        );
        $this->load($location, '2026-09-09 10:00:00');

        $this->expectException(NormalizedTransactionException::class);

        $this->createTransaction(
            $location,
            '20.00',
            NormalizedTransactionType::Sale,
            '2026-09-09 09:30:00',
        );
    }

    public function test_calculated_units_are_derived_from_transaction_sum_for_historical_load(): void
    {
        $location = $this->location('20.00');
        $load = $this->load(
            $location,
            '2026-09-09 08:00:00',
            '2026-09-09 10:00:00',
            TrailerLoadStatus::Completed,
            2,
        );
        $this->createTransaction($location, '25.00', NormalizedTransactionType::Sale, '2026-09-09 08:30:00');
        $this->createTransaction($location, '5.00', NormalizedTransactionType::Refund, '2026-09-09 09:00:00');

        $load = TrailerLoad::query()->withUnitTotals()->findOrFail($load->id);

        $this->assertSame('1.00000000', $load->calculatedUnits());
        $this->assertSame(2, $load->warehouse_actual_count);

        $owner = User::factory()->create(['role' => UserRole::OwnerAdmin]);
        $this->actingAs($owner)
            ->getJson("/api/admin/locations/{$location->id}/trailer-loads")
            ->assertOk()
            ->assertJsonPath('data.0.calculated_units', '1.00000000')
            ->assertJsonPath('data.0.warehouse_actual_count', 2);
    }

    public function test_owner_driver_and_warehouse_apis_expose_calculated_units(): void
    {
        $owner = User::factory()->create(['role' => UserRole::OwnerAdmin]);
        $driver = User::factory()->create(['role' => UserRole::Driver]);
        $warehouse = User::factory()->create(['role' => UserRole::WarehouseStaff]);

        $driverLocation = $this->location('20.00');
        $driverLoad = $this->load($driverLocation, '2026-09-09 08:00:00');
        $driverLoad->update([
            'committed_driver_id' => $driver->id,
            'commitment_source' => DriverCommitmentSource::OpenClaim,
            'committed_at' => CarbonImmutable::parse('2026-09-09 08:00:00'),
            'committed_by_user_id' => $driver->id,
        ]);
        $this->createTransaction($driverLocation, '17.80', NormalizedTransactionType::Sale, '2026-09-09 09:00:00');

        $warehouseLocation = $this->location('20.00');
        $warehouseLoad = $this->load(
            $warehouseLocation,
            '2026-09-09 08:00:00',
            '2026-09-09 10:00:00',
            TrailerLoadStatus::PendingWarehouseCount,
        );
        $this->createTransaction($warehouseLocation, '25.00', NormalizedTransactionType::Sale, '2026-09-09 09:00:00');

        $this->actingAs($owner)
            ->getJson("/api/admin/locations/{$driverLocation->id}/trailer-loads/{$driverLoad->id}")
            ->assertOk()
            ->assertJsonPath('data.calculated_units', '0.89000000')
            ->assertJsonPath('data.location.haul_threshold', '70.00');

        $this->actingAs($driver)
            ->getJson('/api/driver/trailer-loads')
            ->assertOk()
            ->assertJsonPath('data.my_routes.0.calculated_units', '0.89000000')
            ->assertJsonPath('data.my_routes.0.location.haul_threshold', '70.00');

        $this->actingAs($warehouse)
            ->getJson('/api/warehouse/trailer-loads')
            ->assertOk()
            ->assertJsonPath('data.0.id', $warehouseLoad->id)
            ->assertJsonPath('data.0.calculated_units', '1.25000000');
    }

    public function test_owner_can_view_safe_normalized_transaction_fields(): void
    {
        $owner = User::factory()->create(['role' => UserRole::OwnerAdmin]);
        [$location, $load] = $this->locationAndLoad('20.00');
        $this->createTransaction($location, '17.80', NormalizedTransactionType::Sale);

        $response = $this->actingAs($owner)
            ->getJson("/api/admin/locations/{$location->id}/trailer-loads/{$load->id}/transactions")
            ->assertOk()
            ->assertJsonPath('data.0.transaction_type', 'sale')
            ->assertJsonPath('data.0.business_amount', '17.80')
            ->assertJsonPath('data.0.unit_price_snapshot', '20.00')
            ->assertJsonPath('data.0.unit_delta', '0.89000000')
            ->assertJsonPath('data.0.source', 'test');

        $this->assertArrayNotHasKey('external_transaction_id', $response->json('data.0'));
    }

    public function test_development_sample_command_is_deterministic_and_local_only(): void
    {
        $this->locationAndLoad('20.00');

        $this->artisan('dejavoo:seed-normalized-transactions')
            ->assertExitCode(Command::SUCCESS);
        $this->artisan('dejavoo:seed-normalized-transactions')
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame(3, NormalizedTransaction::query()
            ->where('source', 'development_sample')
            ->count());
        $this->assertDatabaseHas('normalized_transactions', [
            'source' => 'development_sample',
            'transaction_type' => NormalizedTransactionType::Refund->value,
            'unit_delta' => '-0.89000000',
        ]);

        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('dejavoo:seed-normalized-transactions')
            ->expectsOutput('Sample normalized transactions may only be created locally.')
            ->assertExitCode(Command::FAILURE);
    }

    /**
     * @return array{Location, TrailerLoad}
     */
    private function locationAndLoad(
        string $unitPrice,
        string $startedAt = '2026-09-09 08:00:00',
    ): array {
        $location = $this->location($unitPrice);

        return [$location, $this->load($location, $startedAt)];
    }

    private function location(string $unitPrice): Location
    {
        $location = Location::query()->create([
            'name' => 'Normalized Transaction Test '.uniqid(),
            'unit_price' => $unitPrice,
            'haul_threshold' => '70.00',
            'route_type' => LocationRouteType::Open,
            'is_active' => true,
        ]);

        app(LocationPriceService::class)->ensureHistory($location);

        return $location;
    }

    private function load(
        Location $location,
        string $startedAt,
        ?string $swappedAt = null,
        TrailerLoadStatus $status = TrailerLoadStatus::Active,
        ?int $actualCount = null,
    ): TrailerLoad {
        $creator = User::factory()->create(['role' => UserRole::OwnerAdmin]);

        return TrailerLoad::query()->create([
            'location_id' => $location->id,
            'status' => $status,
            'started_at' => CarbonImmutable::parse($startedAt),
            'created_by_user_id' => $creator->id,
            'swapped_at' => $swappedAt ? CarbonImmutable::parse($swappedAt) : null,
            'warehouse_actual_count' => $actualCount,
        ]);
    }

    private function createTransaction(
        Location $location,
        string $amount,
        NormalizedTransactionType $type,
        string $occurredAt = '2026-09-09 08:30:00',
    ): NormalizedTransaction {
        return app(NormalizedTransactionService::class)->create(
            locationId: $location->id,
            transactionType: $type,
            businessAmount: $amount,
            occurredAt: CarbonImmutable::parse($occurredAt),
            source: 'test',
        );
    }
}
