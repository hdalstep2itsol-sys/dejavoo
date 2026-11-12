<?php

namespace Tests\Feature;

use App\Enums\LocationRouteType;
use App\Enums\NormalizedTransactionType;
use App\Enums\TrailerLoadStatus;
use App\Enums\UserRole;
use App\Models\IpospaysFeedEvent;
use App\Models\Location;
use App\Models\TrailerLoad;
use App\Models\User;
use App\Notifications\IpospaysFeedFailureAlert;
use App\Notifications\TrailerLoadThresholdReached;
use App\Notifications\WarehouseLoadPending;
use App\Services\IpospaysFeedFailureAlerter;
use App\Services\LocationPriceService;
use App\Services\NormalizedTransactionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class OperationalNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::clear();
        Notification::fake();
    }

    public function test_threshold_notification_is_queued_once_for_active_owner_admins(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $inactiveOwner = $this->user(UserRole::OwnerAdmin, false);
        [$location, $load] = $this->locationAndLoad('2.00');
        $service = app(NormalizedTransactionService::class);

        $service->create(
            $location->id,
            NormalizedTransactionType::Sale,
            '20.00',
            now()->addSecond(),
            'test',
            'threshold-1',
        );
        Notification::assertNothingSent();

        $service->create(
            $location->id,
            NormalizedTransactionType::Sale,
            '20.00',
            now()->addSecond(),
            'test',
            'threshold-2',
        );
        $service->create(
            $location->id,
            NormalizedTransactionType::Sale,
            '20.00',
            now()->addSecond(),
            'test',
            'threshold-3',
        );

        Notification::assertSentToTimes($owner, TrailerLoadThresholdReached::class, 1);
        Notification::assertNotSentTo($inactiveOwner, TrailerLoadThresholdReached::class);
        Notification::assertSentTo($owner, TrailerLoadThresholdReached::class, function ($notification): bool {
            return $notification instanceof ShouldQueue;
        });
        $this->assertNotNull($load->refresh()->threshold_notified_at);
    }

    public function test_manual_adjustment_can_trigger_the_same_one_time_threshold_notification(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        [$location, $load] = $this->locationAndLoad('2.00', $owner);

        $path = "/api/admin/locations/{$location->id}/trailer-loads/{$load->id}/adjustments";
        $this->actingAs($owner)->postJson($path, [
            'unit_delta' => '2.00000000',
            'reason' => 'Operational correction reaches threshold',
        ])->assertCreated();
        $this->actingAs($owner)->postJson($path, [
            'unit_delta' => '1.00000000',
            'reason' => 'Subsequent correction',
        ])->assertCreated();

        Notification::assertSentToTimes($owner, TrailerLoadThresholdReached::class, 1);
    }

    public function test_swap_notification_is_queued_once_for_active_warehouse_users(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $driver = $this->user(UserRole::Driver);
        $warehouseOne = $this->user(UserRole::WarehouseStaff);
        $warehouseTwo = $this->user(UserRole::WarehouseStaff);
        $inactiveWarehouse = $this->user(UserRole::WarehouseStaff, false);
        [$location, $load] = $this->locationAndLoad('70.00', $owner, $driver);

        $this->actingAs($driver)
            ->postJson("/api/driver/trailer-loads/{$load->id}/swap")
            ->assertOk();
        $this->actingAs($driver)
            ->postJson("/api/driver/trailer-loads/{$load->id}/swap")
            ->assertConflict();

        Notification::assertSentToTimes($warehouseOne, WarehouseLoadPending::class, 1);
        Notification::assertSentToTimes($warehouseTwo, WarehouseLoadPending::class, 1);
        Notification::assertNotSentTo($inactiveWarehouse, WarehouseLoadPending::class);
        Notification::assertSentTo($warehouseOne, WarehouseLoadPending::class, function ($notification): bool {
            return $notification instanceof ShouldQueue;
        });
        $this->assertNotNull($load->refresh()->warehouse_notified_at);
        $this->assertSame(TrailerLoadStatus::PendingWarehouseCount, $load->status);
        $this->assertSame(2, $location->trailerLoads()->count());
    }

    public function test_feed_failure_alert_is_thresholded_rate_limited_and_safe(): void
    {
        config()->set('operations.feed_alerts.recipient', 'operations@example.test');
        config()->set('operations.feed_alerts.threshold', 2);
        config()->set('operations.feed_alerts.window_seconds', 900);
        $alerter = app(IpospaysFeedFailureAlerter::class);

        $alerter->record('payload_mapping');
        Notification::assertNothingSent();
        $alerter->record('transaction_processing');
        $alerter->record('transaction_processing');

        Notification::assertSentOnDemand(
            IpospaysFeedFailureAlert::class,
            function (IpospaysFeedFailureAlert $notification, array $channels, object $notifiable): bool {
                return $channels === ['mail']
                    && $notifiable->routes['mail'] === 'operations@example.test'
                    && $notification->failureCount === 2
                    && $notification instanceof ShouldQueue;
            },
        );
        Notification::assertCount(1);
        $this->assertSame(3, $alerter->recentFailureCount());
    }

    public function test_owner_dashboard_exposes_only_safe_feed_health_status(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        [$location] = $this->locationAndLoad('70.00', $owner);
        $transaction = app(NormalizedTransactionService::class)->create(
            $location->id,
            NormalizedTransactionType::Sale,
            '20.00',
            now()->addSecond(),
            'ipospays_feed',
            'dashboard-feed-health',
        );
        IpospaysFeedEvent::query()->create([
            'provider_event_id' => 'safe-event-id',
            'provider_transaction_id' => 'safe-transaction-id',
            'status' => 'processed',
            'delivery_count' => 1,
            'last_received_at' => now(),
            'authenticated_at' => now(),
            'processed_at' => now(),
            'location_id' => $location->id,
            'normalized_transaction_id' => $transaction->id,
        ]);

        $this->actingAs($owner)
            ->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.feed_status.state', 'healthy')
            ->assertJsonPath('data.feed_status.recent_failure_count', 0)
            ->assertJsonStructure(['data' => ['feed_status' => [
                'last_processed_at', 'failure_window_seconds',
            ]]])
            ->assertJsonMissingPath('data.feed_status.payload')
            ->assertJsonMissingPath('data.feed_status.exception_message');

        app(IpospaysFeedFailureAlerter::class)->record('transaction_processing');

        $this->actingAs($owner)
            ->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.feed_status.state', 'warning')
            ->assertJsonPath('data.feed_status.recent_failure_count', 1);
    }

    private function user(UserRole $role, bool $active = true): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => $active]);
    }

    /** @return array{Location, TrailerLoad} */
    private function locationAndLoad(
        string $threshold,
        ?User $owner = null,
        ?User $driver = null,
    ): array {
        $owner ??= $this->user(UserRole::OwnerAdmin);
        $location = Location::query()->create([
            'name' => 'Notification Location '.uniqid(),
            'unit_price' => '20.00',
            'haul_threshold' => $threshold,
            'route_type' => LocationRouteType::Open,
            'is_active' => true,
        ]);
        app(LocationPriceService::class)->ensureHistory($location);
        $load = TrailerLoad::query()->create([
            'location_id' => $location->id,
            'status' => TrailerLoadStatus::Active,
            'started_at' => now()->subHour(),
            'created_by_user_id' => $owner->id,
            'committed_driver_id' => $driver?->id,
            'commitment_source' => $driver ? 'open_claim' : null,
            'committed_at' => $driver ? now() : null,
            'committed_by_user_id' => $driver?->id,
        ]);

        return [$location, $load];
    }
}
