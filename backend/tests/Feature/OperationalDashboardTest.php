<?php

namespace Tests\Feature;

use App\Enums\DriverCommitmentSource;
use App\Enums\LocationRouteType;
use App\Enums\TrailerLoadStatus;
use App\Enums\UserRole;
use App\Models\Location;
use App\Models\TrailerLoad;
use App\Models\TrailerLoadAdjustment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationalDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_summary_and_active_location_rows_derive_from_database_data(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $driver = $this->user(UserRole::Driver);
        $readyLocation = $this->location('Ready', LocationRouteType::Dedicated, true, $driver);
        $activeLocation = $this->location('Below', LocationRouteType::Open);
        $openLocation = $this->location('Open', LocationRouteType::Open);
        $inactiveLocation = $this->location('Inactive', LocationRouteType::Open, false);

        $ready = $this->load($readyLocation, $owner, TrailerLoadStatus::Active, $driver);
        $below = $this->load($activeLocation, $owner);
        $this->load($openLocation, $owner);
        $this->adjust($ready, $owner, '105.00000000');
        $this->adjust($below, $owner, '35.00000000');
        $inactiveLoad = $this->load($inactiveLocation, $owner);
        $this->adjust($inactiveLoad, $owner, '105.00000000');

        $pendingLocation = $this->location('Pending', LocationRouteType::Open);
        $this->load($pendingLocation, $owner, TrailerLoadStatus::PendingWarehouseCount, $driver, [
            'swapped_at' => now(),
            'swapped_by_user_id' => $driver->id,
        ]);

        $response = $this->actingAs($owner)->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.summary.active_locations', 4)
            ->assertJsonPath('data.summary.ready_loads', 1)
            ->assertJsonPath('data.summary.awaiting_warehouse_count', 1)
            ->assertJsonPath('data.summary.open_unclaimed_active_loads', 2)
            ->assertJsonCount(4, 'data.locations');

        $rows = collect($response->json('data.locations'))->keyBy('name');
        $this->assertSame('ready', $rows['Ready']['active_load']['operational_status']);
        $this->assertSame('150.00', $rows['Ready']['active_load']['progress_percentage']);
        $this->assertSame('active', $rows['Below']['active_load']['operational_status']);
        $this->assertSame('50.00', $rows['Below']['active_load']['progress_percentage']);
        $this->assertFalse($rows->has('Inactive'));
        $response->assertJsonMissingPath('data.locations.0.forecast');
    }

    public function test_owner_consolidated_routes_and_history_include_derived_variance(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $driver = $this->user(UserRole::Driver);
        $activeLocation = $this->location('Active route', LocationRouteType::Open);
        $historyLocation = $this->location('History route', LocationRouteType::Open);
        $active = $this->load($activeLocation, $owner);
        $completed = $this->load($historyLocation, $owner, TrailerLoadStatus::Completed, $driver, [
            'swapped_at' => now()->subHour(),
            'swapped_by_user_id' => $driver->id,
            'warehouse_actual_count' => 80,
            'warehouse_confirmed_at' => now(),
            'warehouse_confirmed_by_user_id' => $owner->id,
        ]);
        $this->adjust($active, $owner, '71.00000000');
        $this->adjust($completed, $owner, '75.25000000');

        $this->actingAs($owner)->getJson('/api/admin/routes')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $active->id)
            ->assertJsonPath('data.0.operational_status', 'ready');

        $this->actingAs($owner)->getJson("/api/admin/load-history?location_id={$historyLocation->id}&status=completed")
            ->assertOk()
            ->assertJsonCount(1, 'data.loads')
            ->assertJsonPath('data.loads.0.id', $completed->id)
            ->assertJsonPath('data.loads.0.warehouse_variance', '4.75000000');

        $this->actingAs($owner)->getJson('/api/admin/load-history?status=active')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_driver_dashboard_only_returns_relevant_routes_history_and_ready_count(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $driver = $this->user(UserRole::Driver);
        $otherDriver = $this->user(UserRole::Driver);

        $mine = $this->load($this->location('Mine', LocationRouteType::Open), $owner, TrailerLoadStatus::Active, $driver);
        $this->adjust($mine, $owner, '70.00000000');
        $open = $this->load($this->location('Available', LocationRouteType::Open), $owner);
        $other = $this->load($this->location('Other dedicated', LocationRouteType::Dedicated, true, $otherDriver), $owner, TrailerLoadStatus::Active, $otherDriver);
        $myHistory = $this->load($this->location('My history', LocationRouteType::Open), $owner, TrailerLoadStatus::Completed, $driver, [
            'swapped_at' => now(),
            'swapped_by_user_id' => $driver->id,
            'warehouse_actual_count' => 69,
        ]);
        $this->load($this->location('Other history', LocationRouteType::Open), $owner, TrailerLoadStatus::Completed, $otherDriver, [
            'swapped_at' => now(),
            'swapped_by_user_id' => $otherDriver->id,
        ]);

        $response = $this->actingAs($driver)->getJson('/api/driver/trailer-loads')
            ->assertOk()
            ->assertJsonPath('data.summary.my_active_routes', 1)
            ->assertJsonPath('data.summary.open_routes', 1)
            ->assertJsonPath('data.summary.ready_loads', 1)
            ->assertJsonCount(1, 'data.my_routes')
            ->assertJsonCount(1, 'data.open_routes')
            ->assertJsonCount(1, 'data.history')
            ->assertJsonPath('data.my_routes.0.id', $mine->id)
            ->assertJsonPath('data.open_routes.0.id', $open->id)
            ->assertJsonPath('data.history.0.id', $myHistory->id);

        $ids = collect($response->json('data.my_routes'))
            ->concat($response->json('data.open_routes'))
            ->pluck('id');
        $this->assertNotContains($other->id, $ids);
    }

    public function test_warehouse_dashboard_has_real_pending_count_and_only_my_recent_confirmations(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $warehouse = $this->user(UserRole::WarehouseStaff);
        $otherWarehouse = $this->user(UserRole::WarehouseStaff);
        $driver = $this->user(UserRole::Driver);

        $pending = $this->load($this->location('Pending', LocationRouteType::Open), $owner, TrailerLoadStatus::PendingWarehouseCount, $driver, [
            'swapped_at' => now(),
            'swapped_by_user_id' => $driver->id,
        ]);
        $mine = $this->load($this->location('Confirmed by me', LocationRouteType::Open), $owner, TrailerLoadStatus::Completed, $driver, [
            'swapped_at' => now()->subDay(),
            'swapped_by_user_id' => $driver->id,
            'warehouse_actual_count' => 70,
            'warehouse_confirmed_at' => now(),
            'warehouse_confirmed_by_user_id' => $warehouse->id,
        ]);
        $this->load($this->location('Confirmed by other', LocationRouteType::Open), $owner, TrailerLoadStatus::Completed, $driver, [
            'swapped_at' => now()->subDay(),
            'swapped_by_user_id' => $driver->id,
            'warehouse_actual_count' => 70,
            'warehouse_confirmed_at' => now(),
            'warehouse_confirmed_by_user_id' => $otherWarehouse->id,
        ]);

        $this->actingAs($warehouse)->getJson('/api/warehouse/dashboard')
            ->assertOk()
            ->assertJsonPath('data.summary.awaiting_warehouse_count', 1)
            ->assertJsonCount(1, 'data.pending')
            ->assertJsonPath('data.pending.0.id', $pending->id)
            ->assertJsonCount(1, 'data.recent_confirmed')
            ->assertJsonPath('data.recent_confirmed.0.id', $mine->id);
    }

    public function test_dashboard_role_authorization_is_enforced(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $driver = $this->user(UserRole::Driver);
        $warehouse = $this->user(UserRole::WarehouseStaff);

        $this->actingAs($driver)->getJson('/api/admin/dashboard')->assertForbidden();
        $this->actingAs($warehouse)->getJson('/api/admin/routes')->assertForbidden();
        $this->actingAs($owner)->getJson('/api/warehouse/dashboard')->assertForbidden();
        $this->actingAs($warehouse)->getJson('/api/driver/trailer-loads')->assertForbidden();
    }

    private function user(UserRole $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function location(
        string $name,
        LocationRouteType $routeType,
        bool $active = true,
        ?User $dedicatedDriver = null,
    ): Location {
        return Location::query()->create([
            'name' => $name,
            'unit_price' => '15.00',
            'haul_threshold' => '70.00',
            'route_type' => $routeType,
            'dedicated_driver_id' => $dedicatedDriver?->id,
            'is_active' => $active,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function load(
        Location $location,
        User $creator,
        TrailerLoadStatus $status = TrailerLoadStatus::Active,
        ?User $driver = null,
        array $extra = [],
    ): TrailerLoad {
        return TrailerLoad::query()->create([
            'location_id' => $location->id,
            'status' => $status,
            'started_at' => now()->subDays(2),
            'created_by_user_id' => $creator->id,
            'committed_driver_id' => $driver?->id,
            'commitment_source' => $driver ? DriverCommitmentSource::OpenClaim : null,
            'committed_at' => $driver ? now()->subDay() : null,
            'committed_by_user_id' => $driver?->id,
            ...$extra,
        ]);
    }

    private function adjust(TrailerLoad $load, User $owner, string $units): void
    {
        TrailerLoadAdjustment::query()->create([
            'trailer_load_id' => $load->id,
            'unit_delta' => $units,
            'reason' => 'Dashboard fixture',
            'created_by_user_id' => $owner->id,
        ]);
    }
}
