<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const SPA_HEADERS = [
        'Accept' => 'application/json',
        'Origin' => 'http://localhost:3000',
        'Referer' => 'http://localhost:3000/login',
    ];

    public function test_a_user_can_log_in_with_valid_credentials(): void
    {
        $csrfToken = 'valid-test-csrf-token';
        $user = User::factory()->create([
            'email' => 'owner@example.test',
            'password' => Hash::make('correct-password'),
            'role' => UserRole::OwnerAdmin,
        ]);

        $response = $this
            ->withSession(['_token' => $csrfToken])
            ->withHeaders([...self::SPA_HEADERS, 'X-CSRF-TOKEN' => $csrfToken])
            ->postJson('/api/auth/login', [
                'email' => 'owner@example.test',
                'password' => 'correct-password',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.role', UserRole::OwnerAdmin->value);
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_rejects_invalid_credentials(): void
    {
        $csrfToken = 'valid-test-csrf-token';
        User::factory()->create([
            'email' => 'driver@example.test',
            'password' => Hash::make('correct-password'),
        ]);

        $this->withSession(['_token' => $csrfToken])
            ->withHeaders([...self::SPA_HEADERS, 'X-CSRF-TOKEN' => $csrfToken])
            ->postJson('/api/auth/login', [
                'email' => 'driver@example.test',
                'password' => 'wrong-password',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/auth/user')->assertUnauthorized();
    }

    public function test_an_authenticated_user_can_log_out(): void
    {
        $csrfToken = 'valid-test-csrf-token';
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['_token' => $csrfToken])
            ->withHeaders([...self::SPA_HEADERS, 'X-CSRF-TOKEN' => $csrfToken])
            ->postJson('/api/auth/logout')
            ->assertNoContent();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/auth/user')->assertUnauthorized();
    }

    public function test_current_user_requires_authentication(): void
    {
        $this->getJson('/api/auth/user')->assertUnauthorized();
    }

    public function test_current_user_returns_the_authenticated_user(): void
    {
        $user = User::factory()->create(['role' => UserRole::WarehouseStaff]);

        $this->actingAs($user)
            ->getJson('/api/auth/user')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.role', UserRole::WarehouseStaff->value);
    }

    public function test_role_middleware_enforces_the_required_role(): void
    {
        Route::middleware(['auth:sanctum', 'role:owner_admin'])
            ->get('/api/test/owner-only', fn () => response()->json(['ok' => true]));

        $owner = User::factory()->create(['role' => UserRole::OwnerAdmin]);
        $driver = User::factory()->create(['role' => UserRole::Driver]);

        $this->actingAs($owner)
            ->getJson('/api/test/owner-only')
            ->assertOk();

        $this->actingAs($driver)
            ->getJson('/api/test/owner-only')
            ->assertForbidden()
            ->assertJsonPath('message', 'You are not authorized to access this resource.');
    }

    public function test_there_is_no_public_registration_endpoint(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Unapproved User',
            'email' => 'new@example.test',
            'password' => 'unapproved-password',
        ])->assertNotFound();
    }
}
