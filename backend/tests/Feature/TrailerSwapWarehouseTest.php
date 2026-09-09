<?php

namespace Tests\Feature;

use App\Enums\DriverCommitmentSource;
use App\Enums\LocationRouteType;
use App\Enums\TrailerLoadStatus;
use App\Enums\UserRole;
use App\Models\Location;
use App\Models\TrailerLoad;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrailerSwapWarehouseTest extends TestCase
{
    use RefreshDatabase;

    public function test_committed_driver_can_atomically_swap_an_active_open_load(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $driver = $this->user(UserRole::Driver);
        $location = $this->location(LocationRouteType::Open);
        $oldLoad = $this->load($location, $owner, $driver);
        $swapTime = CarbonImmutable::parse('2026-09-09T14:15:16Z');
        $this->travelTo($swapTime);

        $response = $this->actingAs($driver)
            ->postJson("/api/driver/trailer-loads/{$oldLoad->id}/swap")
            ->assertOk()
            ->assertJsonPath('data.swapped_load.status', TrailerLoadStatus::PendingWarehouseCount->value)
            ->assertJsonPath('data.swapped_load.swapped_by.id', $driver->id)
            ->assertJsonPath('data.replacement_load.status', TrailerLoadStatus::Active->value)
            ->assertJsonPath('data.replacement_load.committed_driver', null);

        $oldLoad->refresh();
        $replacement = TrailerLoad::query()->findOrFail(
            $response->json('data.replacement_load.id'),
        );

        $this->assertSame(TrailerLoadStatus::PendingWarehouseCount, $oldLoad->status);
        $this->assertSame($driver->id, $oldLoad->swapped_by_user_id);
        $this->assertSame($driver->id, $oldLoad->committed_driver_id);
        $this->assertSame(DriverCommitmentSource::OpenClaim, $oldLoad->commitment_source);
        $this->assertTrue($oldLoad->swapped_at->equalTo($swapTime));
        $this->assertTrue($replacement->started_at->equalTo($oldLoad->swapped_at));
        $this->assertSame(1, $location->trailerLoads()->where('status', TrailerLoadStatus::Active->value)->count());
    }

    public function test_different_driver_cannot_swap_another_drivers_load(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $committedDriver = $this->user(UserRole::Driver);
        $otherDriver = $this->user(UserRole::Driver);
        $load = $this->load(
            $this->location(LocationRouteType::Open),
            $owner,
            $committedDriver,
        );

        $this->actingAs($otherDriver)
            ->postJson("/api/driver/trailer-loads/{$load->id}/swap")
            ->assertConflict()
            ->assertJsonPath('message', 'Only the committed Driver can swap this trailer/load.');

        $this->assertSame(TrailerLoadStatus::Active, $load->refresh()->status);
        $this->assertDatabaseCount('trailer_loads', 1);
    }

    public function test_unclaimed_open_load_cannot_be_swapped(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $driver = $this->user(UserRole::Driver);
        $load = $this->load($this->location(LocationRouteType::Open), $owner);

        $this->actingAs($driver)
            ->postJson("/api/driver/trailer-loads/{$load->id}/swap")
            ->assertConflict()
            ->assertJsonPath('message', 'This route must be claimed before it can be swapped.');
    }

    public function test_warehouse_staff_cannot_swap_a_load(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $driver = $this->user(UserRole::Driver);
        $warehouseUser = $this->user(UserRole::WarehouseStaff);
        $load = $this->load($this->location(LocationRouteType::Open), $owner, $driver);

        $this->actingAs($warehouseUser)
            ->postJson("/api/driver/trailer-loads/{$load->id}/swap")
            ->assertForbidden();
    }

    public function test_dedicated_replacement_uses_the_locations_current_dedicated_driver(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $oldDriver = $this->user(UserRole::Driver);
        $currentDriver = $this->user(UserRole::Driver);
        $location = $this->location(LocationRouteType::Dedicated, $currentDriver);
        $oldLoad = $this->load($location, $owner, $oldDriver, DriverCommitmentSource::Dedicated);

        $response = $this->actingAs($oldDriver)
            ->postJson("/api/driver/trailer-loads/{$oldLoad->id}/swap")
            ->assertOk()
            ->assertJsonPath('data.swapped_load.committed_driver.id', $oldDriver->id)
            ->assertJsonPath('data.replacement_load.committed_driver.id', $currentDriver->id)
            ->assertJsonPath(
                'data.replacement_load.commitment_source',
                DriverCommitmentSource::Dedicated->value,
            );

        $this->assertSame($oldDriver->id, $oldLoad->refresh()->committed_driver_id);
        $this->assertDatabaseHas('trailer_loads', [
            'id' => $response->json('data.replacement_load.id'),
            'committed_driver_id' => $currentDriver->id,
            'commitment_source' => DriverCommitmentSource::Dedicated->value,
        ]);
    }

    public function test_repeated_swap_attempt_cannot_create_another_replacement(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $driver = $this->user(UserRole::Driver);
        $location = $this->location(LocationRouteType::Open);
        $oldLoad = $this->load($location, $owner, $driver);
        $url = "/api/driver/trailer-loads/{$oldLoad->id}/swap";

        $this->actingAs($driver)->postJson($url)->assertOk();
        $this->actingAs($driver)->postJson($url)->assertConflict();

        $this->assertSame(2, $location->trailerLoads()->count());
        $this->assertSame(
            1,
            $location->trailerLoads()
                ->where('status', TrailerLoadStatus::Active->value)
                ->count(),
        );
    }

    public function test_warehouse_staff_can_list_and_view_only_pending_loads(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $warehouseUser = $this->user(UserRole::WarehouseStaff);
        $driver = $this->user(UserRole::Driver);
        $pending = $this->load(
            $this->location(LocationRouteType::Open, null, 'Pending Location'),
            $owner,
            $driver,
            DriverCommitmentSource::OpenClaim,
            TrailerLoadStatus::PendingWarehouseCount,
            ['swapped_at' => now(), 'swapped_by_user_id' => $driver->id],
        );
        $active = $this->load(
            $this->location(LocationRouteType::Open, null, 'Active Location'),
            $owner,
        );

        $this->actingAs($warehouseUser)
            ->getJson('/api/warehouse/trailer-loads')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $pending->id)
            ->assertJsonPath('data.0.swapped_by.id', $driver->id);

        $this->actingAs($warehouseUser)
            ->getJson("/api/warehouse/trailer-loads/{$pending->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $pending->id);

        $this->actingAs($warehouseUser)
            ->getJson("/api/warehouse/trailer-loads/{$active->id}")
            ->assertNotFound();
    }

    public function test_warehouse_staff_can_confirm_a_pending_load(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $warehouseUser = $this->user(UserRole::WarehouseStaff);
        $driver = $this->user(UserRole::Driver);
        $load = $this->pendingLoad($owner, $driver);
        $confirmationTime = CarbonImmutable::parse('2026-09-10T09:10:11Z');
        $this->travelTo($confirmationTime);

        $this->actingAs($warehouseUser)
            ->postJson("/api/warehouse/trailer-loads/{$load->id}/confirm", [
                'actual_count' => 72,
                'notes' => 'Counted after unloading.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', TrailerLoadStatus::Completed->value)
            ->assertJsonPath('data.warehouse_actual_count', 72)
            ->assertJsonPath('data.warehouse_notes', 'Counted after unloading.')
            ->assertJsonPath('data.warehouse_confirmed_by.id', $warehouseUser->id);

        $load->refresh();
        $this->assertSame(TrailerLoadStatus::Completed, $load->status);
        $this->assertTrue($load->warehouse_confirmed_at->equalTo($confirmationTime));
        $this->assertSame($warehouseUser->id, $load->warehouse_confirmed_by_user_id);
    }

    public function test_actual_count_must_be_a_non_negative_integer(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $warehouseUser = $this->user(UserRole::WarehouseStaff);
        $driver = $this->user(UserRole::Driver);
        $load = $this->pendingLoad($owner, $driver);
        $url = "/api/warehouse/trailer-loads/{$load->id}/confirm";

        $this->actingAs($warehouseUser)
            ->postJson($url, ['actual_count' => -1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('actual_count');

        $this->actingAs($warehouseUser)
            ->postJson($url, ['actual_count' => 1.5])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('actual_count');
    }

    public function test_warehouse_notes_are_optional(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $warehouseUser = $this->user(UserRole::WarehouseStaff);
        $driver = $this->user(UserRole::Driver);
        $load = $this->pendingLoad($owner, $driver);

        $this->actingAs($warehouseUser)
            ->postJson("/api/warehouse/trailer-loads/{$load->id}/confirm", [
                'actual_count' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('data.warehouse_notes', null);
    }

    public function test_active_load_cannot_be_warehouse_confirmed(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $warehouseUser = $this->user(UserRole::WarehouseStaff);
        $load = $this->load($this->location(LocationRouteType::Open), $owner);

        $this->actingAs($warehouseUser)
            ->postJson("/api/warehouse/trailer-loads/{$load->id}/confirm", ['actual_count' => 10])
            ->assertConflict();
    }

    public function test_completed_load_cannot_be_confirmed_again(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $warehouseUser = $this->user(UserRole::WarehouseStaff);
        $driver = $this->user(UserRole::Driver);
        $load = $this->pendingLoad($owner, $driver);
        $url = "/api/warehouse/trailer-loads/{$load->id}/confirm";

        $this->actingAs($warehouseUser)
            ->postJson($url, ['actual_count' => 10])
            ->assertOk();

        $this->actingAs($warehouseUser)
            ->postJson($url, ['actual_count' => 11])
            ->assertConflict();

        $this->assertSame(10, $load->refresh()->warehouse_actual_count);
    }

    public function test_driver_cannot_perform_warehouse_confirmation(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $driver = $this->user(UserRole::Driver);
        $load = $this->pendingLoad($owner, $driver);

        $this->actingAs($driver)
            ->postJson("/api/warehouse/trailer-loads/{$load->id}/confirm", [
                'actual_count' => 10,
            ])
            ->assertForbidden();
    }

    private function user(UserRole $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function location(
        LocationRouteType $routeType,
        ?User $dedicatedDriver = null,
        string $name = 'Lifecycle Location',
    ): Location {
        return Location::query()->create([
            'name' => $name,
            'unit_price' => '15.00',
            'haul_threshold' => '70.00',
            'route_type' => $routeType,
            'dedicated_driver_id' => $dedicatedDriver?->id,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function load(
        Location $location,
        User $creator,
        ?User $committedDriver = null,
        DriverCommitmentSource $source = DriverCommitmentSource::OpenClaim,
        TrailerLoadStatus $status = TrailerLoadStatus::Active,
        array $extra = [],
    ): TrailerLoad {
        return TrailerLoad::query()->create([
            'location_id' => $location->id,
            'status' => $status,
            'started_at' => '2026-09-01T12:30:00Z',
            'created_by_user_id' => $creator->id,
            'committed_driver_id' => $committedDriver?->id,
            'commitment_source' => $committedDriver ? $source : null,
            'committed_at' => $committedDriver ? now() : null,
            'committed_by_user_id' => $committedDriver?->id,
            ...$extra,
        ]);
    }

    private function pendingLoad(User $owner, User $driver): TrailerLoad
    {
        return $this->load(
            $this->location(LocationRouteType::Open),
            $owner,
            $driver,
            DriverCommitmentSource::OpenClaim,
            TrailerLoadStatus::PendingWarehouseCount,
            ['swapped_at' => now(), 'swapped_by_user_id' => $driver->id],
        );
    }
}
