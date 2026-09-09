<?php

namespace Tests\Feature\Admin;

use App\Enums\LocationRouteType;
use App\Enums\UserRole;
use App\Models\DejavooTerminal;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocationManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_admin_request_returns_unauthorized_for_any_client(): void
    {
        $this->get('/api/admin/locations')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_owner_admin_can_list_create_and_update_locations(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);

        $created = $this->actingAs($owner)->postJson('/api/admin/locations', [
            'name' => 'North Drop-Off',
            'unit_price' => '15.50',
            'haul_threshold' => '70.00',
            'route_type' => LocationRouteType::Open->value,
        ]);

        $created
            ->assertCreated()
            ->assertJsonPath('data.name', 'North Drop-Off')
            ->assertJsonPath('data.route_type', LocationRouteType::Open->value);

        $locationId = $created->json('data.id');

        $this->actingAs($owner)
            ->getJson('/api/admin/locations')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($owner)
            ->putJson("/api/admin/locations/{$locationId}", [
                'name' => 'North Transfer Site',
                'unit_price' => '16.25',
                'haul_threshold' => '72.50',
                'route_type' => LocationRouteType::Open->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'North Transfer Site')
            ->assertJsonPath('data.unit_price', '16.25');
    }

    public function test_driver_cannot_access_owner_admin_location_management(): void
    {
        $driver = $this->user(UserRole::Driver);

        $this->actingAs($driver)
            ->getJson('/api/admin/locations')
            ->assertForbidden();

        $this->actingAs($driver)
            ->postJson('/api/admin/locations', $this->openLocationData())
            ->assertForbidden();
    }

    public function test_warehouse_staff_cannot_access_owner_admin_location_management(): void
    {
        $warehouseUser = $this->user(UserRole::WarehouseStaff);

        $this->actingAs($warehouseUser)
            ->getJson('/api/admin/locations')
            ->assertForbidden();
    }

    public function test_unit_price_must_be_greater_than_zero(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);

        $this->actingAs($owner)
            ->postJson('/api/admin/locations', $this->openLocationData([
                'unit_price' => '0.00',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('unit_price');
    }

    public function test_haul_threshold_must_be_greater_than_zero(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);

        $this->actingAs($owner)
            ->postJson('/api/admin/locations', $this->openLocationData([
                'haul_threshold' => '-1.00',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('haul_threshold');
    }

    public function test_dedicated_route_requires_a_driver(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);

        $this->actingAs($owner)
            ->postJson('/api/admin/locations', $this->openLocationData([
                'route_type' => LocationRouteType::Dedicated->value,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('dedicated_driver_id');
    }

    public function test_non_driver_user_cannot_be_assigned_to_a_dedicated_route(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $warehouseUser = $this->user(UserRole::WarehouseStaff);

        $this->actingAs($owner)
            ->postJson('/api/admin/locations', $this->openLocationData([
                'route_type' => LocationRouteType::Dedicated->value,
                'dedicated_driver_id' => $warehouseUser->id,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('dedicated_driver_id');
    }

    public function test_open_route_does_not_retain_a_dedicated_driver(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $driver = $this->user(UserRole::Driver);
        $location = Location::query()->create([
            ...$this->openLocationData(),
            'route_type' => LocationRouteType::Dedicated,
            'dedicated_driver_id' => $driver->id,
        ]);

        $this->actingAs($owner)
            ->putJson("/api/admin/locations/{$location->id}", [
                'route_type' => LocationRouteType::Open->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.dedicated_driver', null);

        $this->assertDatabaseHas('locations', [
            'id' => $location->id,
            'route_type' => LocationRouteType::Open->value,
            'dedicated_driver_id' => null,
        ]);
    }

    public function test_owner_admin_can_activate_and_deactivate_a_location(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $location = Location::query()->create($this->openLocationData());

        $this->actingAs($owner)
            ->patchJson("/api/admin/locations/{$location->id}/status", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->actingAs($owner)
            ->patchJson("/api/admin/locations/{$location->id}/status", ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
    }

    public function test_owner_admin_can_create_and_update_a_terminal_mapping(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $location = Location::query()->create($this->openLocationData());

        $created = $this->actingAs($owner)
            ->postJson("/api/admin/locations/{$location->id}/terminals", [
                'tpn' => 'TEST-TPN-001',
                'term_id' => 'TEST-TERM-001',
            ])
            ->assertCreated()
            ->assertJsonPath('data.tpn', 'TEST-TPN-001');

        $terminalId = $created->json('data.id');

        $this->actingAs($owner)
            ->putJson("/api/admin/locations/{$location->id}/terminals/{$terminalId}", [
                'tpn' => 'TEST-TPN-002',
                'term_id' => 'TEST-TERM-002',
            ])
            ->assertOk()
            ->assertJsonPath('data.tpn', 'TEST-TPN-002')
            ->assertJsonPath('data.term_id', 'TEST-TERM-002');
    }

    public function test_duplicate_non_null_terminal_identifiers_are_rejected(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $firstLocation = Location::query()->create($this->openLocationData(['name' => 'First']));
        $secondLocation = Location::query()->create($this->openLocationData(['name' => 'Second']));
        DejavooTerminal::query()->create([
            'location_id' => $firstLocation->id,
            'tpn' => 'DUPLICATE-TPN',
            'term_id' => 'DUPLICATE-TERM',
        ]);

        $this->actingAs($owner)
            ->postJson("/api/admin/locations/{$secondLocation->id}/terminals", [
                'tpn' => 'DUPLICATE-TPN',
                'term_id' => 'UNIQUE-TERM',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tpn');

        $this->actingAs($owner)
            ->postJson("/api/admin/locations/{$secondLocation->id}/terminals", [
                'tpn' => 'UNIQUE-TPN',
                'term_id' => 'DUPLICATE-TERM',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('term_id');
    }

    public function test_owner_admin_can_activate_and_deactivate_a_terminal_mapping(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $location = Location::query()->create($this->openLocationData());
        $terminal = DejavooTerminal::query()->create([
            'location_id' => $location->id,
            'tpn' => 'STATUS-TPN',
            'is_active' => true,
        ]);

        $this->actingAs($owner)
            ->patchJson("/api/admin/locations/{$location->id}/terminals/{$terminal->id}/status", [
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->actingAs($owner)
            ->patchJson("/api/admin/locations/{$location->id}/terminals/{$terminal->id}/status", [
                'is_active' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
    }

    private function user(UserRole $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function openLocationData(array $overrides = []): array
    {
        return [
            'name' => 'Default Drop-Off',
            'unit_price' => '15.00',
            'haul_threshold' => '70.00',
            'route_type' => LocationRouteType::Open->value,
            'is_active' => true,
            ...$overrides,
        ];
    }
}
