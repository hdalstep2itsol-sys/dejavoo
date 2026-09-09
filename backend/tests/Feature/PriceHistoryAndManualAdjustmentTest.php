<?php

namespace Tests\Feature;

use App\Enums\LocationRouteType;
use App\Enums\NormalizedTransactionType;
use App\Enums\TrailerLoadStatus;
use App\Enums\UserRole;
use App\Models\Location;
use App\Models\TrailerLoad;
use App\Models\User;
use App\Services\LocationPriceService;
use App\Services\NormalizedTransactionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PriceHistoryAndManualAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-09-09 08:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_new_location_creates_initial_price_history(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);

        $response = $this->actingAs($owner)->postJson('/api/admin/locations', [
            'name' => 'Price History Location',
            'unit_price' => '20.00',
            'haul_threshold' => '70.00',
            'route_type' => LocationRouteType::Open->value,
        ])->assertCreated();

        $locationId = $response->json('data.id');

        $this->assertDatabaseHas('location_price_histories', [
            'location_id' => $locationId,
            'unit_price' => '20.00',
            'created_by_user_id' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->getJson("/api/admin/locations/{$locationId}/price-history")
            ->assertOk()
            ->assertJsonPath('data.0.unit_price', '20.00');
    }

    public function test_existing_location_price_can_be_safely_represented_in_history(): void
    {
        $location = $this->locationWithoutHistory('18.50');

        app(LocationPriceService::class)->ensureHistory($location);

        $this->assertDatabaseHas('location_price_histories', [
            'location_id' => $location->id,
            'unit_price' => '18.50',
            'created_by_user_id' => null,
        ]);
    }

    public function test_price_change_adds_history_and_preserves_old_record(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $location = $this->location('20.00');
        $oldHistory = $location->priceHistory()->firstOrFail();
        CarbonImmutable::setTestNow('2026-09-09 10:00:00');

        $this->actingAs($owner)
            ->putJson("/api/admin/locations/{$location->id}", ['unit_price' => '25.00'])
            ->assertOk()
            ->assertJsonPath('data.unit_price', '25.00');

        $this->assertSame(2, $location->priceHistory()->count());
        $this->assertSame('20.00', $oldHistory->refresh()->unit_price);
        $this->assertSame('25.00', $location->refresh()->unit_price);
        $this->assertSame('25.00', $location->priceHistory()
            ->orderByDesc('effective_from')->firstOrFail()->unit_price);
    }

    public function test_transactions_resolve_effective_price_even_when_old_event_arrives_late(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $location = $this->location('20.00');
        $this->load($location, TrailerLoadStatus::Active);
        CarbonImmutable::setTestNow('2026-09-09 10:00:00');

        $this->actingAs($owner)
            ->putJson("/api/admin/locations/{$location->id}", ['unit_price' => '25.00'])
            ->assertOk();

        $service = app(NormalizedTransactionService::class);
        $lateOld = $service->create(
            $location->id,
            NormalizedTransactionType::Sale,
            '20.00',
            CarbonImmutable::parse('2026-09-09 09:00:00'),
            'test',
        );
        $new = $service->create(
            $location->id,
            NormalizedTransactionType::Sale,
            '25.00',
            CarbonImmutable::parse('2026-09-09 10:00:01'),
            'test',
        );

        $this->assertSame('20.00', $lateOld->unit_price_snapshot);
        $this->assertSame('1.00000000', $lateOld->unit_delta);
        $this->assertSame('25.00', $new->unit_price_snapshot);
        $this->assertSame('1.00000000', $new->unit_delta);
    }

    public function test_owner_can_add_signed_adjustments_and_totals_remain_separate(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $location = $this->location('20.00');
        $load = $this->load($location, TrailerLoadStatus::Completed, 3);
        app(NormalizedTransactionService::class)->create(
            $location->id,
            NormalizedTransactionType::Sale,
            '20.00',
            CarbonImmutable::parse('2026-09-09 08:30:00'),
            'test',
        );

        $this->actingAs($owner)
            ->postJson($this->adjustmentPath($location, $load), [
                'unit_delta' => '1.50000000',
                'reason' => 'Recovered units found during review',
            ])
            ->assertCreated()
            ->assertJsonPath('data.unit_delta', '1.50000000')
            ->assertJsonPath('data.created_by.id', $owner->id);

        $this->actingAs($owner)
            ->postJson($this->adjustmentPath($location, $load), [
                'unit_delta' => '-0.25000000',
                'reason' => 'Compensating correction',
            ])
            ->assertCreated();

        $this->actingAs($owner)
            ->getJson("/api/admin/locations/{$location->id}/trailer-loads/{$load->id}")
            ->assertOk()
            ->assertJsonPath('data.calculated_units', '1.00000000')
            ->assertJsonPath('data.manual_adjustment_units', '1.25000000')
            ->assertJsonPath('data.operational_units', '2.25000000')
            ->assertJsonPath('data.warehouse_actual_count', 3);

        $this->actingAs($owner)
            ->getJson($this->adjustmentPath($location, $load))
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_zero_adjustment_and_missing_reason_are_rejected(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $location = $this->location('20.00');
        $load = $this->load($location, TrailerLoadStatus::Active);

        $this->actingAs($owner)
            ->postJson($this->adjustmentPath($location, $load), [
                'unit_delta' => '0.00000000',
                'reason' => 'No change',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('unit_delta');

        $this->actingAs($owner)
            ->postJson($this->adjustmentPath($location, $load), [
                'unit_delta' => '1.00000000',
                'reason' => '   ',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
    }

    public function test_driver_and_warehouse_staff_cannot_create_adjustments(): void
    {
        $location = $this->location('20.00');
        $load = $this->load($location, TrailerLoadStatus::Active);

        foreach ([UserRole::Driver, UserRole::WarehouseStaff] as $role) {
            $this->actingAs($this->user($role))
                ->postJson($this->adjustmentPath($location, $load), [
                    'unit_delta' => '1.00000000',
                    'reason' => 'Unauthorized adjustment',
                ])
                ->assertForbidden();
        }
    }

    private function location(string $price): Location
    {
        $location = $this->locationWithoutHistory($price);
        app(LocationPriceService::class)->ensureHistory($location);

        return $location;
    }

    private function locationWithoutHistory(string $price): Location
    {
        return Location::query()->create([
            'name' => 'History Test '.uniqid(),
            'unit_price' => $price,
            'haul_threshold' => '70.00',
            'route_type' => LocationRouteType::Open,
            'is_active' => true,
        ]);
    }

    private function load(
        Location $location,
        TrailerLoadStatus $status,
        ?int $warehouseActualCount = null,
    ): TrailerLoad {
        $creator = $this->user(UserRole::OwnerAdmin);

        return TrailerLoad::query()->create([
            'location_id' => $location->id,
            'status' => $status,
            'started_at' => CarbonImmutable::parse('2026-09-09 08:00:00'),
            'created_by_user_id' => $creator->id,
            'swapped_at' => $status === TrailerLoadStatus::Active
                ? null
                : CarbonImmutable::parse('2026-09-09 12:00:00'),
            'warehouse_actual_count' => $warehouseActualCount,
        ]);
    }

    private function user(UserRole $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function adjustmentPath(Location $location, TrailerLoad $load): string
    {
        return "/api/admin/locations/{$location->id}/trailer-loads/{$load->id}/adjustments";
    }
}
