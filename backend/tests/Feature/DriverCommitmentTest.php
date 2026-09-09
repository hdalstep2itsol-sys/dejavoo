<?php

namespace Tests\Feature;

use App\Enums\DriverCommitmentSource;
use App\Enums\LocationRouteType;
use App\Enums\TrailerLoadStatus;
use App\Enums\UserRole;
use App\Models\Location;
use App\Models\TrailerLoad;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DriverCommitmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_dedicated_active_load_receives_its_dedicated_driver_commitment(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $driver = $this->user(UserRole::Driver);
        $location = $this->location(LocationRouteType::Dedicated, $driver);

        $response = $this->actingAs($owner)
            ->postJson("/api/admin/locations/{$location->id}/trailer-loads", [
                'started_at' => '2026-09-01T12:30:00Z',
            ])
            ->assertCreated()
            ->assertJsonPath('data.committed_driver.id', $driver->id)
            ->assertJsonPath('data.commitment_source', DriverCommitmentSource::Dedicated->value)
            ->assertJsonPath('data.committed_by.id', $owner->id);

        $this->assertDatabaseHas('trailer_loads', [
            'id' => $response->json('data.id'),
            'committed_driver_id' => $driver->id,
            'commitment_source' => DriverCommitmentSource::Dedicated->value,
            'committed_by_user_id' => $owner->id,
        ]);
    }

    public function test_open_route_load_starts_without_a_committed_driver(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $location = $this->location(LocationRouteType::Open);

        $this->actingAs($owner)
            ->postJson("/api/admin/locations/{$location->id}/trailer-loads", [
                'started_at' => '2026-09-01T12:30:00Z',
            ])
            ->assertCreated()
            ->assertJsonPath('data.committed_driver', null)
            ->assertJsonPath('data.commitment_source', null)
            ->assertJsonPath('data.committed_at', null);
    }

    public function test_driver_can_claim_an_open_load(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $driver = $this->user(UserRole::Driver);
        $load = $this->load($this->location(LocationRouteType::Open), $owner);

        $this->actingAs($driver)
            ->postJson("/api/driver/trailer-loads/{$load->id}/claim")
            ->assertOk()
            ->assertJsonPath('data.committed_driver.id', $driver->id)
            ->assertJsonPath('data.commitment_source', DriverCommitmentSource::OpenClaim->value)
            ->assertJsonPath('data.committed_by.id', $driver->id);
    }

    public function test_driver_cannot_claim_a_dedicated_load(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $driver = $this->user(UserRole::Driver);
        $dedicatedDriver = $this->user(UserRole::Driver);
        $load = $this->load(
            $this->location(LocationRouteType::Dedicated, $dedicatedDriver),
            $owner,
        );

        $this->actingAs($driver)
            ->postJson("/api/driver/trailer-loads/{$load->id}/claim")
            ->assertConflict()
            ->assertJsonPath('message', 'Dedicated routes cannot be claimed.');
    }

    public function test_driver_cannot_claim_a_load_committed_to_another_driver(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $firstDriver = $this->user(UserRole::Driver);
        $secondDriver = $this->user(UserRole::Driver);
        $load = $this->load($this->location(LocationRouteType::Open), $owner);
        $this->commit($load, $firstDriver, DriverCommitmentSource::OpenClaim, $firstDriver);

        $this->actingAs($secondDriver)
            ->postJson("/api/driver/trailer-loads/{$load->id}/claim")
            ->assertConflict()
            ->assertJsonPath('message', 'This route has already been claimed.');
    }

    public function test_two_competing_claims_cannot_both_succeed(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $firstDriver = $this->user(UserRole::Driver);
        $secondDriver = $this->user(UserRole::Driver);
        $load = $this->load($this->location(LocationRouteType::Open), $owner);

        $this->actingAs($firstDriver)
            ->postJson("/api/driver/trailer-loads/{$load->id}/claim")
            ->assertOk();

        $this->actingAs($secondDriver)
            ->postJson("/api/driver/trailer-loads/{$load->id}/claim")
            ->assertConflict();

        $this->assertDatabaseHas('trailer_loads', [
            'id' => $load->id,
            'committed_driver_id' => $firstDriver->id,
            'committed_by_user_id' => $firstDriver->id,
        ]);
    }

    public function test_non_driver_cannot_be_assigned_as_committed_driver(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $warehouseUser = $this->user(UserRole::WarehouseStaff);
        $load = $this->load($this->location(LocationRouteType::Open), $owner);

        $this->actingAs($owner)
            ->putJson($this->adminCommitmentUrl($load), ['driver_id' => $warehouseUser->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('driver_id');
    }

    public function test_admin_can_assign_change_view_and_remove_a_commitment(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $firstDriver = $this->user(UserRole::Driver);
        $secondDriver = $this->user(UserRole::Driver);
        $load = $this->load($this->location(LocationRouteType::Open), $owner);
        $url = $this->adminCommitmentUrl($load);

        $this->actingAs($owner)
            ->putJson($url, ['driver_id' => $firstDriver->id])
            ->assertOk()
            ->assertJsonPath('data.committed_driver.id', $firstDriver->id)
            ->assertJsonPath('data.committed_by.id', $owner->id);

        $this->actingAs($owner)
            ->putJson($url, ['driver_id' => $secondDriver->id])
            ->assertOk()
            ->assertJsonPath('data.committed_driver.id', $secondDriver->id);

        $this->actingAs($owner)
            ->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.committed_driver.id', $secondDriver->id);

        $this->actingAs($owner)
            ->deleteJson($url)
            ->assertOk()
            ->assertJsonPath('data.committed_driver', null)
            ->assertJsonPath('data.commitment_source', null)
            ->assertJsonPath('data.committed_at', null);
    }

    public function test_warehouse_staff_cannot_manage_commitments(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $warehouseUser = $this->user(UserRole::WarehouseStaff);
        $driver = $this->user(UserRole::Driver);
        $load = $this->load($this->location(LocationRouteType::Open), $owner);

        $this->actingAs($warehouseUser)
            ->putJson($this->adminCommitmentUrl($load), ['driver_id' => $driver->id])
            ->assertForbidden();

        $this->actingAs($warehouseUser)
            ->deleteJson($this->adminCommitmentUrl($load))
            ->assertForbidden();
    }

    public function test_driver_only_sees_owned_routes_and_available_open_routes(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $driver = $this->user(UserRole::Driver);
        $otherDriver = $this->user(UserRole::Driver);

        $dedicated = $this->load(
            $this->location(LocationRouteType::Dedicated, $driver, 'My Dedicated'),
            $owner,
        );
        $this->commit($dedicated, $driver, DriverCommitmentSource::Dedicated, $owner);

        $claimedOpen = $this->load(
            $this->location(LocationRouteType::Open, null, 'My Claimed Open'),
            $owner,
        );
        $this->commit($claimedOpen, $driver, DriverCommitmentSource::OpenClaim, $driver);

        $availableOpen = $this->load(
            $this->location(LocationRouteType::Open, null, 'Available Open'),
            $owner,
        );

        $otherClaimed = $this->load(
            $this->location(LocationRouteType::Open, null, 'Other Claimed'),
            $owner,
        );
        $this->commit($otherClaimed, $otherDriver, DriverCommitmentSource::OpenClaim, $otherDriver);

        $otherDedicated = $this->load(
            $this->location(LocationRouteType::Dedicated, $otherDriver, 'Other Dedicated'),
            $owner,
        );
        $this->commit($otherDedicated, $otherDriver, DriverCommitmentSource::Dedicated, $owner);

        $response = $this->actingAs($driver)
            ->getJson('/api/driver/trailer-loads')
            ->assertOk()
            ->assertJsonCount(2, 'data.my_routes')
            ->assertJsonCount(1, 'data.open_routes');

        $this->assertEqualsCanonicalizing(
            [$dedicated->id, $claimedOpen->id],
            collect($response->json('data.my_routes'))->pluck('id')->all(),
        );
        $this->assertSame($availableOpen->id, $response->json('data.open_routes.0.id'));
    }

    public function test_non_active_load_cannot_be_claimed(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $driver = $this->user(UserRole::Driver);
        $load = $this->load(
            $this->location(LocationRouteType::Open),
            $owner,
            TrailerLoadStatus::Completed,
        );

        $this->actingAs($driver)
            ->postJson("/api/driver/trailer-loads/{$load->id}/claim")
            ->assertConflict()
            ->assertJsonPath('message', 'Only an active trailer/load can be claimed.');
    }

    public function test_admin_cannot_assign_a_driver_to_a_non_active_load(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $driver = $this->user(UserRole::Driver);
        $load = $this->load(
            $this->location(LocationRouteType::Open),
            $owner,
            TrailerLoadStatus::PendingWarehouseCount,
        );

        $this->actingAs($owner)
            ->putJson($this->adminCommitmentUrl($load), ['driver_id' => $driver->id])
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'Only an active trailer/load can receive a commitment.',
            );
    }

    private function user(UserRole $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function location(
        LocationRouteType $routeType,
        ?User $dedicatedDriver = null,
        string $name = 'Commitment Location',
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

    private function load(
        Location $location,
        User $creator,
        TrailerLoadStatus $status = TrailerLoadStatus::Active,
    ): TrailerLoad {
        return TrailerLoad::query()->create([
            'location_id' => $location->id,
            'status' => $status,
            'started_at' => '2026-09-01T12:30:00Z',
            'created_by_user_id' => $creator->id,
        ]);
    }

    private function commit(
        TrailerLoad $load,
        User $driver,
        DriverCommitmentSource $source,
        User $performedBy,
    ): void {
        $load->update([
            'committed_driver_id' => $driver->id,
            'commitment_source' => $source,
            'committed_at' => now(),
            'committed_by_user_id' => $performedBy->id,
        ]);
    }

    private function adminCommitmentUrl(TrailerLoad $load): string
    {
        return "/api/admin/locations/{$load->location_id}/trailer-loads/{$load->id}/commitment";
    }
}
