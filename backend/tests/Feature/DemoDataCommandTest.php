<?php

namespace Tests\Feature;

use App\Enums\LocationRouteType;
use App\Enums\TrailerLoadStatus;
use App\Enums\UserRole;
use App\Models\DejavooTerminal;
use App\Models\Location;
use App\Models\LocationPriceHistory;
use App\Models\NormalizedTransaction;
use App\Models\TrailerLoad;
use App\Models\TrailerLoadAdjustment;
use App\Models\User;
use App\Services\DemoDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DemoDataCommandTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Environment-Demo-Password-123!';

    public function test_command_runs_in_local_environment(): void
    {
        $this->seedDemo('local');

        $this->assertDemoDatasetCounts();
    }

    public function test_command_runs_in_production_environment(): void
    {
        $this->seedDemo('production');

        $this->assertDemoDatasetCounts();
    }

    public function test_local_and_production_environments_produce_the_same_deterministic_dataset(): void
    {
        $this->seedDemo('local');
        $localFingerprint = $this->demoDatasetFingerprint();

        $this->setEnvironment('production');
        $this->artisan('dejavoo:seed-demo', ['--reset' => true])->assertSuccessful();

        $this->assertSame($localFingerprint, $this->demoDatasetFingerprint());
    }

    public function test_command_requires_the_environment_password(): void
    {
        $this->setEnvironment('production');
        config(['demo.user_password' => '']);

        $this->artisan('dejavoo:seed-demo')
            ->expectsOutput('DEV_TEST_USER_PASSWORD is required and must contain at least 12 characters.')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_command_creates_complete_deterministic_demo_data(): void
    {
        $this->seedDemo();

        $this->assertSame(7, User::query()->whereIn('email', DemoDataService::USER_EMAILS)->count());
        $this->assertSame(7, Location::query()->whereIn('name', DemoDataService::LOCATION_NAMES)->count());
        $this->assertSame(8, DejavooTerminal::query()->where('tpn', 'like', 'DEMO-TPN-%')->count());
        $this->assertSame(13, TrailerLoad::query()->count());
        $this->assertSame(20, NormalizedTransaction::query()->where('source', DemoDataService::SOURCE)->count());
        $this->assertSame(8, TrailerLoadAdjustment::query()->whereIn('reason', DemoDataService::DEMO_ADJUSTMENT_REASONS)->count());

        $this->assertSame(6, TrailerLoad::query()->where('status', TrailerLoadStatus::Active)->count());
        $this->assertSame(2, TrailerLoad::query()->where('status', TrailerLoadStatus::PendingWarehouseCount)->count());
        $this->assertSame(5, TrailerLoad::query()->where('status', TrailerLoadStatus::Completed)->count());

        $expectedUsers = [
            'owner.admin@example.test' => ['Demo Owner', UserRole::OwnerAdmin, true],
            'mike.driver@example.test' => ['Mike Driver', UserRole::Driver, true],
            'john.driver@example.test' => ['John Driver', UserRole::Driver, true],
            'sarah.driver@example.test' => ['Sarah Driver', UserRole::Driver, true],
            'inactive.driver@example.test' => ['Inactive Driver', UserRole::Driver, false],
            'warehouse@example.test' => ['Wendy Warehouse', UserRole::WarehouseStaff, true],
            'warehouse.two@example.test' => ['Walter Warehouse', UserRole::WarehouseStaff, true],
        ];
        $seededUsers = User::query()->whereIn('email', DemoDataService::USER_EMAILS)->get()->keyBy('email');
        $this->assertEqualsCanonicalizing(array_keys($expectedUsers), $seededUsers->keys()->all());
        foreach ($expectedUsers as $email => [$name, $role, $isActive]) {
            $user = $seededUsers->get($email);
            $this->assertNotNull($user);
            $this->assertSame($name, $user->name);
            $this->assertSame($role, $user->role);
            $this->assertSame($isActive, $user->is_active);
            $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        }

        $this->assertDatabaseHas('users', [
            'email' => 'owner.admin@example.test',
            'name' => 'Demo Owner',
            'role' => UserRole::OwnerAdmin->value,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('users', [
            'email' => 'inactive.driver@example.test',
            'role' => UserRole::Driver->value,
            'is_active' => false,
        ]);
        $this->assertTrue(Hash::check(
            self::PASSWORD,
            User::query()->where('email', 'mike.driver@example.test')->value('password'),
        ));
        $this->assertDatabaseHas('locations', ['name' => 'Transfer Station', 'is_active' => false]);
        $this->assertDatabaseHas('locations', [
            'name' => 'Landfill',
            'unit_price' => '20.00',
            'haul_threshold' => '70.00',
        ]);
        $this->assertDatabaseHas('locations', [
            'name' => 'Rockvale',
            'unit_price' => '25.00',
            'haul_threshold' => '70.00',
        ]);
        $this->assertDatabaseMissing('dejavoo_terminals', ['tpn' => 'DEMO-TPN-001', 'term_id' => null]);

        $owner = User::query()->where('email', 'owner.admin@example.test')->firstOrFail();
        $this->actingAs($owner)->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.summary.active_locations', 6)
            ->assertJsonPath('data.summary.ready_loads', 3)
            ->assertJsonPath('data.summary.awaiting_warehouse_count', 2)
            ->assertJsonPath('data.summary.open_unclaimed_active_loads', 1);

        $mike = User::query()->where('email', 'mike.driver@example.test')->firstOrFail();
        $this->actingAs($mike)->getJson('/api/driver/trailer-loads')
            ->assertOk()
            ->assertJsonPath('data.summary.my_active_routes', 2)
            ->assertJsonPath('data.summary.open_routes', 1)
            ->assertJsonPath('data.summary.ready_loads', 1)
            ->assertJsonCount(3, 'data.history');

        $warehouse = User::query()->where('email', 'warehouse@example.test')->firstOrFail();
        $this->actingAs($warehouse)->getJson('/api/warehouse/dashboard')
            ->assertOk()
            ->assertJsonPath('data.summary.awaiting_warehouse_count', 2)
            ->assertJsonCount(3, 'data.recent_confirmed');

        $this->actingAs($owner)->getJson('/api/admin/reports')
            ->assertOk()
            ->assertJsonPath('meta.total', 13);
    }

    public function test_normal_rerun_does_not_duplicate_demo_records(): void
    {
        $this->seedDemo();
        $this->seedDemo();

        $this->assertDatabaseCount('users', 7);
        $this->assertDatabaseCount('locations', 7);
        $this->assertDatabaseCount('dejavoo_terminals', 8);
        $this->assertDatabaseCount('trailer_loads', 13);
        $this->assertDatabaseCount('normalized_transactions', 20);
        $this->assertDatabaseCount('trailer_load_adjustments', 8);
    }

    public function test_reset_restores_demo_lifecycle_after_ui_changes(): void
    {
        $this->seedDemo('production');
        $mike = User::query()->where('email', 'mike.driver@example.test')->firstOrFail();
        $load = TrailerLoad::query()
            ->whereHas('location', fn ($query) => $query->where('name', 'Nolensville'))
            ->where('status', TrailerLoadStatus::Active)
            ->firstOrFail();

        $this->actingAs($mike)
            ->postJson("/api/driver/trailer-loads/{$load->id}/swap")
            ->assertOk();
        $this->assertDatabaseCount('trailer_loads', 14);

        $this->artisan('dejavoo:seed-demo', ['--reset' => true])->assertSuccessful();

        $this->assertDatabaseCount('trailer_loads', 13);
        $this->assertSame(
            6,
            TrailerLoad::query()->where('status', TrailerLoadStatus::Active)->count(),
        );
        $this->assertSame(
            2,
            TrailerLoad::query()->where('status', TrailerLoadStatus::PendingWarehouseCount)->count(),
        );
    }

    public function test_reset_refuses_to_delete_non_demo_data_from_demo_locations(): void
    {
        $this->seedDemo('production');
        $owner = User::query()->where('email', 'owner.admin@example.test')->firstOrFail();
        $load = TrailerLoad::query()->firstOrFail();
        TrailerLoadAdjustment::query()->create([
            'trailer_load_id' => $load->id,
            'unit_delta' => '1.00000000',
            'reason' => 'Manual UI test that must be preserved',
            'created_by_user_id' => $owner->id,
        ]);

        $this->artisan('dejavoo:seed-demo', ['--reset' => true])
            ->expectsOutput('Reset refused: a demo location contains non-demo terminal, transaction, adjustment, or price-history data.')
            ->assertFailed();

        $this->assertDatabaseCount('locations', 7);
        $this->assertDatabaseHas('trailer_load_adjustments', [
            'reason' => 'Manual UI test that must be preserved',
        ]);
    }

    public function test_seed_refuses_to_overwrite_an_unowned_location_with_the_same_name(): void
    {
        $this->setEnvironment('production');
        config(['demo.user_password' => self::PASSWORD]);
        Location::query()->create([
            'name' => 'Landfill',
            'unit_price' => '99.00',
            'haul_threshold' => '70.00',
            'route_type' => LocationRouteType::Open,
            'dedicated_driver_id' => null,
            'is_active' => true,
        ]);

        $this->artisan('dejavoo:seed-demo')
            ->expectsOutput("Demo seed refused: location 'Landfill' already exists without its expected demo terminal marker.")
            ->assertFailed();

        $this->assertDatabaseHas('locations', ['name' => 'Landfill', 'unit_price' => '99.00']);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('dejavoo_terminals', 0);
    }

    public function test_reset_refuses_to_delete_non_demo_price_history(): void
    {
        $this->seedDemo('production');
        $owner = User::query()->where('email', 'owner.admin@example.test')->firstOrFail();
        $location = Location::query()->where('name', 'Landfill')->firstOrFail();
        LocationPriceHistory::query()->create([
            'location_id' => $location->id,
            'unit_price' => '21.00',
            'effective_from' => '2026-09-15 00:00:00.000000',
            'created_by_user_id' => $owner->id,
        ]);

        $this->artisan('dejavoo:seed-demo', ['--reset' => true])
            ->expectsOutput('Reset refused: a demo location contains non-demo terminal, transaction, adjustment, or price-history data.')
            ->assertFailed();

        $this->assertDatabaseHas('location_price_histories', [
            'location_id' => $location->id,
            'unit_price' => '21.00',
        ]);
    }

    private function seedDemo(string $environment = 'local'): void
    {
        $this->setEnvironment($environment);
        config(['demo.user_password' => self::PASSWORD]);
        $this->artisan('dejavoo:seed-demo')->assertSuccessful();
    }

    private function setEnvironment(string $environment): void
    {
        $this->app->detectEnvironment(fn () => $environment);
    }

    private function assertDemoDatasetCounts(): void
    {
        $this->assertSame(7, User::query()->whereIn('email', DemoDataService::USER_EMAILS)->count());
        $this->assertSame(7, Location::query()->whereIn('name', DemoDataService::LOCATION_NAMES)->count());
        $this->assertSame(8, DejavooTerminal::query()->where('tpn', 'like', 'DEMO-TPN-%')->count());
        $this->assertSame(13, TrailerLoad::query()->count());
        $this->assertSame(20, NormalizedTransaction::query()->where('source', DemoDataService::SOURCE)->count());
        $this->assertSame(8, TrailerLoadAdjustment::query()->whereIn('reason', DemoDataService::DEMO_ADJUSTMENT_REASONS)->count());
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function demoDatasetFingerprint(): array
    {
        return [
            'users' => User::query()
                ->whereIn('email', DemoDataService::USER_EMAILS)
                ->orderBy('email')
                ->get(['email', 'name', 'role', 'is_active'])
                ->toArray(),
            'locations' => Location::query()
                ->whereIn('name', DemoDataService::LOCATION_NAMES)
                ->orderBy('name')
                ->get(['name', 'unit_price', 'haul_threshold', 'route_type', 'is_active'])
                ->toArray(),
            'terminals' => DejavooTerminal::query()
                ->where('tpn', 'like', 'DEMO-TPN-%')
                ->orderBy('tpn')
                ->get(['tpn', 'term_id', 'is_active'])
                ->toArray(),
            'loads' => TrailerLoad::query()
                ->with('location:id,name')
                ->orderBy('started_at')
                ->get()
                ->map(fn (TrailerLoad $load) => [
                    'location' => $load->location->name,
                    'status' => $load->status->value,
                    'started_at' => $load->started_at->format('Y-m-d H:i:s'),
                    'operational_units' => $load->operationalUnits(),
                ])
                ->all(),
            'transactions' => NormalizedTransaction::query()
                ->where('source', DemoDataService::SOURCE)
                ->orderBy('external_transaction_id')
                ->get(['external_transaction_id', 'transaction_type', 'business_amount', 'unit_delta'])
                ->toArray(),
            'adjustments' => TrailerLoadAdjustment::query()
                ->whereIn('reason', DemoDataService::DEMO_ADJUSTMENT_REASONS)
                ->orderBy('reason')
                ->get(['unit_delta', 'reason'])
                ->toArray(),
        ];
    }
}
