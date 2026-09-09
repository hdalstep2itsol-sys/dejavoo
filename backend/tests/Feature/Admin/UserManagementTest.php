<?php

namespace Tests\Feature\Admin;

use App\Enums\DriverCommitmentSource;
use App\Enums\LocationRouteType;
use App\Enums\TrailerLoadStatus;
use App\Enums\UserRole;
use App\Models\Location;
use App\Models\TrailerLoad;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_PASSWORD = 'Strong-Pass!123';

    private const SPA_HEADERS = [
        'Accept' => 'application/json',
        'Origin' => 'http://localhost:3000',
        'Referer' => 'http://localhost:3000/login',
    ];

    public function test_owner_admin_can_list_users_without_password_data(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        User::factory()->create(['name' => 'Listed Driver', 'role' => UserRole::Driver]);

        $response = $this->actingAs($owner)
            ->getJson('/api/admin/users')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        foreach ($response->json('data') as $user) {
            $this->assertArrayNotHasKey('password', $user);
            $this->assertArrayNotHasKey('remember_token', $user);
        }
    }

    public function test_owner_admin_can_create_each_supported_role_and_passwords_are_hashed(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);

        foreach (UserRole::cases() as $index => $role) {
            $response = $this->actingAs($owner)->postJson('/api/admin/users', [
                'name' => "Managed User {$index}",
                'email' => "managed{$index}@example.test",
                'role' => $role->value,
                'password' => self::VALID_PASSWORD,
                'is_active' => true,
            ])->assertCreated()
                ->assertJsonPath('data.role', $role->value)
                ->assertJsonMissingPath('data.password');

            $created = User::query()->findOrFail($response->json('data.id'));
            $this->assertTrue(Hash::check(self::VALID_PASSWORD, $created->password));
            $this->assertNotSame(self::VALID_PASSWORD, $created->password);
        }
    }

    public function test_duplicate_email_and_invalid_role_are_rejected(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        User::factory()->create(['email' => 'taken@example.test']);

        $this->actingAs($owner)->postJson('/api/admin/users', [
            'name' => 'Duplicate Email',
            'email' => 'TAKEN@example.test',
            'role' => UserRole::Driver->value,
            'password' => self::VALID_PASSWORD,
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->actingAs($owner)->postJson('/api/admin/users', [
            'name' => 'Invalid Role',
            'email' => 'invalid-role@example.test',
            'role' => 'manager',
            'password' => self::VALID_PASSWORD,
        ])->assertUnprocessable()->assertJsonValidationErrors('role');
    }

    public function test_driver_and_warehouse_staff_cannot_access_user_management(): void
    {
        foreach ([UserRole::Driver, UserRole::WarehouseStaff] as $role) {
            $user = $this->user($role);

            $this->actingAs($user)
                ->getJson('/api/admin/users')
                ->assertForbidden();

            $this->actingAs($user)
                ->postJson('/api/admin/users', [
                    'name' => 'Unauthorized User',
                    'email' => "unauthorized-{$role->value}@example.test",
                    'role' => UserRole::Driver->value,
                    'password' => self::VALID_PASSWORD,
                ])
                ->assertForbidden();
        }
    }

    public function test_owner_admin_can_view_and_edit_a_user(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $user = $this->user(UserRole::Driver);

        $this->actingAs($owner)
            ->getJson("/api/admin/users/{$user->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);

        $this->actingAs($owner)
            ->putJson("/api/admin/users/{$user->id}", [
                'name' => 'Updated Warehouse User',
                'email' => 'updated@example.test',
                'role' => UserRole::WarehouseStaff->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Warehouse User')
            ->assertJsonPath('data.role', UserRole::WarehouseStaff->value)
            ->assertJsonMissingPath('data.password');
    }

    public function test_owner_admin_can_deactivate_and_reactivate_a_user(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $user = $this->user(UserRole::Driver);

        $this->actingAs($owner)
            ->patchJson("/api/admin/users/{$user->id}/status", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->actingAs($owner)
            ->patchJson("/api/admin/users/{$user->id}/status", ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $user = User::factory()->create([
            'email' => 'inactive@example.test',
            'password' => self::VALID_PASSWORD,
            'is_active' => false,
        ]);
        $csrfToken = 'valid-test-csrf-token';

        $this->withSession(['_token' => $csrfToken])
            ->withHeaders([...self::SPA_HEADERS, 'X-CSRF-TOKEN' => $csrfToken])
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => self::VALID_PASSWORD,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertGuest();
    }

    public function test_deactivated_authenticated_user_cannot_continue_using_protected_apis(): void
    {
        $user = $this->user(UserRole::Driver);
        $user->update(['is_active' => false]);

        $this->actingAs($user)
            ->getJson('/api/driver/trailer-loads')
            ->assertForbidden()
            ->assertJsonPath('message', 'This user account is inactive.');

        $this->actingAs($user)
            ->getJson('/api/auth/user')
            ->assertForbidden();
    }

    public function test_inactive_driver_is_excluded_from_new_assignment_options(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $activeDriver = $this->user(UserRole::Driver);
        $inactiveDriver = User::factory()->create([
            'role' => UserRole::Driver,
            'is_active' => false,
        ]);

        $this->actingAs($owner)
            ->getJson('/api/admin/drivers')
            ->assertOk()
            ->assertJsonFragment(['id' => $activeDriver->id])
            ->assertJsonMissing(['id' => $inactiveDriver->id]);
    }

    public function test_deactivating_driver_preserves_and_surfaces_existing_relationships(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $driver = $this->user(UserRole::Driver);
        $location = Location::query()->create([
            'name' => 'Historical Dedicated Route',
            'unit_price' => '20.00',
            'haul_threshold' => '70.00',
            'route_type' => LocationRouteType::Dedicated,
            'dedicated_driver_id' => $driver->id,
            'is_active' => true,
        ]);
        $load = TrailerLoad::query()->create([
            'location_id' => $location->id,
            'status' => TrailerLoadStatus::Active,
            'started_at' => now()->subHour(),
            'created_by_user_id' => $owner->id,
            'committed_driver_id' => $driver->id,
            'commitment_source' => DriverCommitmentSource::Dedicated,
            'committed_at' => now()->subHour(),
            'committed_by_user_id' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->patchJson("/api/admin/users/{$driver->id}/status", ['is_active' => false])
            ->assertOk();

        $this->assertDatabaseHas('locations', [
            'id' => $location->id,
            'dedicated_driver_id' => $driver->id,
        ]);
        $this->assertDatabaseHas('trailer_loads', [
            'id' => $load->id,
            'committed_driver_id' => $driver->id,
        ]);

        $this->actingAs($owner)
            ->getJson("/api/admin/locations/{$location->id}")
            ->assertOk()
            ->assertJsonPath('data.dedicated_driver.id', $driver->id)
            ->assertJsonPath('data.dedicated_driver.is_active', false);

        $this->actingAs($owner)
            ->getJson("/api/admin/locations/{$location->id}/trailer-loads/{$load->id}")
            ->assertOk()
            ->assertJsonPath('data.committed_driver.id', $driver->id)
            ->assertJsonPath('data.committed_driver.is_active', false);
    }

    private function user(UserRole $role): User
    {
        return User::factory()->create(['role' => $role]);
    }
}
