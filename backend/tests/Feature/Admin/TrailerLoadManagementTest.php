<?php

namespace Tests\Feature\Admin;

use App\Enums\LocationRouteType;
use App\Enums\TrailerLoadStatus;
use App\Enums\UserRole;
use App\Models\Location;
use App\Models\TrailerLoad;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrailerLoadManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_admin_can_initialize_an_active_load(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $location = $this->location();

        $this->actingAs($owner)
            ->postJson("/api/admin/locations/{$location->id}/trailer-loads", [
                'started_at' => '2026-09-01T12:30:00Z',
            ])
            ->assertCreated()
            ->assertJsonPath('data.location_id', $location->id)
            ->assertJsonPath('data.status', TrailerLoadStatus::Active->value)
            ->assertJsonPath('data.created_by.id', $owner->id);

        $this->assertDatabaseHas('trailer_loads', [
            'location_id' => $location->id,
            'status' => TrailerLoadStatus::Active->value,
            'created_by_user_id' => $owner->id,
        ]);
    }

    public function test_driver_cannot_initialize_a_load(): void
    {
        $driver = $this->user(UserRole::Driver);
        $location = $this->location();

        $this->actingAs($driver)
            ->postJson("/api/admin/locations/{$location->id}/trailer-loads", [
                'started_at' => '2026-09-01T12:30:00Z',
            ])
            ->assertForbidden();
    }

    public function test_warehouse_staff_cannot_initialize_a_load(): void
    {
        $warehouseUser = $this->user(UserRole::WarehouseStaff);
        $location = $this->location();

        $this->actingAs($warehouseUser)
            ->postJson("/api/admin/locations/{$location->id}/trailer-loads", [
                'started_at' => '2026-09-01T12:30:00Z',
            ])
            ->assertForbidden();
    }

    public function test_started_at_is_required(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $location = $this->location();

        $this->actingAs($owner)
            ->postJson("/api/admin/locations/{$location->id}/trailer-loads", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('started_at');
    }

    public function test_only_one_active_load_can_exist_per_location(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $location = $this->location();
        $endpoint = "/api/admin/locations/{$location->id}/trailer-loads";

        $this->actingAs($owner)
            ->postJson($endpoint, ['started_at' => '2026-09-01T12:30:00Z'])
            ->assertCreated();

        $this->actingAs($owner)
            ->postJson($endpoint, ['started_at' => '2026-09-02T12:30:00Z'])
            ->assertConflict()
            ->assertJsonPath('message', 'This location already has an active trailer/load.');

        $this->assertSame(1, $location->trailerLoads()->count());
    }

    public function test_database_invariant_prevents_concurrent_active_load_inserts(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $location = $this->location();

        $this->load($location, $owner, TrailerLoadStatus::Active);

        try {
            $this->load($location, $owner, TrailerLoadStatus::Active, '2026-09-02T12:30:00Z');
            $this->fail('The database accepted two active loads for one location.');
        } catch (QueryException) {
            $this->assertSame(
                1,
                $location->trailerLoads()
                    ->where('status', TrailerLoadStatus::Active->value)
                    ->count(),
            );
        }
    }

    public function test_current_active_load_endpoint_works(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $location = $this->location();

        $this->actingAs($owner)
            ->getJson("/api/admin/locations/{$location->id}/trailer-loads/current")
            ->assertOk()
            ->assertJsonPath('data', null);

        $load = $this->load($location, $owner, TrailerLoadStatus::Active);

        $this->actingAs($owner)
            ->getJson("/api/admin/locations/{$location->id}/trailer-loads/current")
            ->assertOk()
            ->assertJsonPath('data.id', $load->id)
            ->assertJsonPath('data.location.name', $location->name);
    }

    public function test_load_list_and_detail_endpoints_work(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $location = $this->location();
        $completed = $this->load($location, $owner, TrailerLoadStatus::Completed);
        $active = $this->load(
            $location,
            $owner,
            TrailerLoadStatus::Active,
            '2026-09-02T12:30:00Z',
        );

        $this->actingAs($owner)
            ->getJson("/api/admin/locations/{$location->id}/trailer-loads")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $active->id)
            ->assertJsonPath('data.1.id', $completed->id);

        $this->actingAs($owner)
            ->getJson("/api/admin/locations/{$location->id}/trailer-loads/{$completed->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $completed->id)
            ->assertJsonPath('data.status', TrailerLoadStatus::Completed->value);
    }

    public function test_load_detail_is_scoped_to_its_location(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $location = $this->location();
        $otherLocation = $this->location('Other Drop-Off');
        $load = $this->load($location, $owner, TrailerLoadStatus::Completed);

        $this->actingAs($owner)
            ->getJson("/api/admin/locations/{$otherLocation->id}/trailer-loads/{$load->id}")
            ->assertNotFound();
    }

    public function test_trailer_load_has_correct_location_and_creator_relationships(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $location = $this->location();
        $load = $this->load($location, $owner, TrailerLoadStatus::Active);

        $this->assertTrue($load->location->is($location));
        $this->assertTrue($load->createdBy->is($owner));
        $this->assertTrue($location->trailerLoads->contains($load));
    }

    public function test_location_creation_does_not_automatically_create_a_load(): void
    {
        $location = $this->location();

        $this->assertDatabaseCount('trailer_loads', 0);
        $this->assertTrue($location->trailerLoads->isEmpty());
    }

    private function user(UserRole $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function location(string $name = 'Lifecycle Drop-Off'): Location
    {
        return Location::query()->create([
            'name' => $name,
            'unit_price' => '15.00',
            'haul_threshold' => '70.00',
            'route_type' => LocationRouteType::Open,
            'is_active' => true,
        ]);
    }

    private function load(
        Location $location,
        User $creator,
        TrailerLoadStatus $status,
        string $startedAt = '2026-09-01T12:30:00Z',
    ): TrailerLoad {
        return TrailerLoad::query()->create([
            'location_id' => $location->id,
            'status' => $status,
            'started_at' => $startedAt,
            'created_by_user_id' => $creator->id,
        ]);
    }
}
