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
use App\Notifications\RouteAssignmentNotice;
use App\Notifications\TrailerLoadEarlyWarning;
use App\Notifications\TrailerLoadThresholdReached;
use App\Notifications\WarehouseCountConfirmed;
use App\Notifications\WarehouseLoadPending;
use App\Services\IpospaysFeedFailureAlerter;
use App\Services\LocationPriceService;
use App\Services\NormalizedTransactionService;
use App\Services\OperationalNotificationService;
use App\Services\TrailerLoadCommitmentService;
use App\Services\TrailerSwapService;
use App\Services\WarehouseConfirmationService;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
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

    public function test_early_warning_is_disabled_by_default_and_crosses_once_for_active_open_route_drivers(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $driver = $this->user(UserRole::Driver);
        $inactive = $this->user(UserRole::Driver, false);
        [$location, $load] = $this->locationAndLoad('3.00', $owner);
        $service = app(NormalizedTransactionService::class);
        $service->create($location->id, NormalizedTransactionType::Sale, '20.00', now()->addSecond(), 'test', 'warning-1');
        Notification::assertNothingSent();

        $location->update(['early_warning_threshold' => '2.00']);
        $service->create($location->id, NormalizedTransactionType::Sale, '20.00', now()->addSecond(), 'test', 'warning-2');
        $service->create($location->id, NormalizedTransactionType::Sale, '20.00', now()->addSecond(), 'test', 'warning-3');
        Notification::assertSentToTimes($owner, TrailerLoadEarlyWarning::class, 1);
        Notification::assertSentToTimes($driver, TrailerLoadEarlyWarning::class, 1);
        Notification::assertNotSentTo($inactive, TrailerLoadEarlyWarning::class);
        Notification::assertSentToTimes($driver, TrailerLoadThresholdReached::class, 1);
        $this->assertNotNull($load->refresh()->early_warning_notified_at);
    }

    public function test_dedicated_load_notifies_only_its_active_driver_and_owner(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $driver = $this->user(UserRole::Driver);
        $other = $this->user(UserRole::Driver);
        [$location] = $this->locationAndLoad('1.00', $owner, $driver);
        $location->update(['route_type' => LocationRouteType::Dedicated, 'dedicated_driver_id' => $driver->id]);
        app(NormalizedTransactionService::class)->create(
            $location->id, NormalizedTransactionType::Sale, '20.00', now()->addSecond(), 'test', 'dedicated-ready',
        );
        Notification::assertSentToTimes($owner, TrailerLoadThresholdReached::class, 1);
        Notification::assertSentToTimes($driver, TrailerLoadThresholdReached::class, 1);
        Notification::assertNotSentTo($other, TrailerLoadThresholdReached::class);
    }

    public function test_open_claim_and_dedicated_assignment_changes_notify_only_affected_active_users(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $driver = $this->user(UserRole::Driver);
        $other = $this->user(UserRole::Driver);
        [$location, $load] = $this->locationAndLoad('70.00', $owner);
        app(TrailerLoadCommitmentService::class)->claim($load, $driver);
        Notification::assertSentToTimes($owner, RouteAssignmentNotice::class, 1);
        Notification::assertNotSentTo($driver, RouteAssignmentNotice::class);

        $location->update(['route_type' => LocationRouteType::Dedicated, 'dedicated_driver_id' => $driver->id]);
        $notifier = app(OperationalNotificationService::class);
        DB::transaction(fn () => $notifier->notifyDedicatedDriverChanged($location, $driver->id, $other->id));
        DB::transaction(fn () => $notifier->notifyDedicatedDriverChanged($location, $other->id, $other->id));
        Notification::assertSentToTimes($driver, RouteAssignmentNotice::class, 1);
        Notification::assertSentToTimes($other, RouteAssignmentNotice::class, 1);
    }

    public function test_swap_and_warehouse_confirmation_notify_management_once_and_rollback_sends_nothing(): void
    {
        $owner = $this->user(UserRole::OwnerAdmin);
        $driver = $this->user(UserRole::Driver);
        $warehouse = $this->user(UserRole::WarehouseStaff);
        [$location, $load] = $this->locationAndLoad('70.00', $owner, $driver);

        DB::beginTransaction();
        $load->update(['status' => TrailerLoadStatus::PendingWarehouseCount, 'swapped_at' => now()]);
        app(OperationalNotificationService::class)->notifyWarehousePending($load);
        Notification::assertNothingSent();
        DB::rollBack();
        $load->refresh();

        app(TrailerSwapService::class)->swap($load, $driver);
        Notification::assertSentToTimes($warehouse, WarehouseLoadPending::class, 1);
        Notification::assertSentToTimes($owner, WarehouseLoadPending::class, 1);
        $confirmed = app(WarehouseConfirmationService::class)->confirm($load, $warehouse, 4, 'Counted');
        Notification::assertSentToTimes($owner, WarehouseCountConfirmed::class, 1);
        Notification::assertSentTo($owner, WarehouseCountConfirmed::class, fn ($notification) => $notification->actualUnits === 4 && $notification->variance === '4.00000000');
        app(OperationalNotificationService::class)->notifyWarehouseConfirmed($confirmed->refresh());
        Notification::assertSentToTimes($owner, WarehouseCountConfirmed::class, 1);
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

    public function test_feed_failure_alert_can_retry_after_queue_dispatch_fails(): void
    {
        config()->set('operations.feed_alerts.recipient', 'operations@example.test');
        config()->set('operations.feed_alerts.threshold', 1);
        config()->set('operations.feed_alerts.window_seconds', 900);
        $alerter = app(IpospaysFeedFailureAlerter::class);
        $alertedKey = 'ipospays-feed:failures:window:'.intdiv(now()->timestamp, 900).':alerted';

        $failingDispatcher = new class implements Dispatcher
        {
            public int $calls = 0;

            public function send($notifiables, $notification): never
            {
                $this->calls++;

                throw new RuntimeException('Queue unavailable');
            }

            public function sendNow($notifiables, $notification, ?array $channels = null): never
            {
                throw new RuntimeException('Queue unavailable');
            }
        };
        $this->app->instance(Dispatcher::class, $failingDispatcher);

        DB::transaction(fn () => $alerter->record('transaction_processing'));
        $this->assertSame(1, $failingDispatcher->calls);
        $this->assertFalse(Cache::has($alertedKey));

        $this->app->instance(Dispatcher::class, Notification::fake());
        $alerter->record('transaction_processing');

        Notification::assertCount(1);
        $this->assertTrue(Cache::has($alertedKey));
    }

    public function test_feed_failure_alert_waits_for_commit_and_rollbacks_do_not_suppress_it(): void
    {
        config()->set('operations.feed_alerts.recipient', 'operations@example.test');
        config()->set('operations.feed_alerts.threshold', 1);
        config()->set('operations.feed_alerts.window_seconds', 900);
        $alerter = app(IpospaysFeedFailureAlerter::class);
        $alertedKey = 'ipospays-feed:failures:window:'.intdiv(now()->timestamp, 900).':alerted';

        DB::beginTransaction();
        $alerter->record('transaction_processing');
        Notification::assertNothingSent();
        $this->assertFalse(Cache::has($alertedKey));
        DB::rollBack();

        $alerter->record('transaction_processing');
        Notification::assertCount(1);
        $this->assertTrue(Cache::has($alertedKey));
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
