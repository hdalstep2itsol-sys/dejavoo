<?php

namespace App\Services;

use App\Enums\LocationRouteType;
use App\Enums\TrailerLoadStatus;
use App\Enums\UserRole;
use App\Models\Location;
use App\Models\TrailerLoad;
use App\Models\User;
use App\Notifications\RouteAssignmentNotice;
use App\Notifications\TrailerLoadEarlyWarning;
use App\Notifications\TrailerLoadThresholdReached;
use App\Notifications\WarehouseCountConfirmed;
use App\Notifications\WarehouseLoadPending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class OperationalNotificationService
{
    public function notifyThresholdIfReached(TrailerLoad $load, string $unitDelta): void
    {
        $load = TrailerLoad::query()->with('location:id,name,haul_threshold,early_warning_threshold,route_type,dedicated_driver_id')
            ->whereKey($load->getKey())->lockForUpdate()->firstOrFail();
        if ($load->status !== TrailerLoadStatus::Active || bccomp($unitDelta, '0', 8) <= 0) {
            return;
        }

        $units = $load->operationalUnits();
        $previous = bcsub($units, $unitDelta, 8);
        $haul = (string) $load->location->haul_threshold;
        $warning = $load->location->early_warning_threshold;
        $driverName = $load->committedDriver?->name ?? 'Unclaimed';
        $recipients = fn () => $this->loadRecipients($load);

        if ($warning !== null && $load->early_warning_notified_at === null
            && bccomp($previous, (string) $warning, 8) < 0
            && bccomp($units, (string) $warning, 8) >= 0) {
            $load->update(['early_warning_notified_at' => now()]);
            DB::afterCommit(fn () => Notification::send($recipients(), new TrailerLoadEarlyWarning(
                $load->getKey(), $load->location->name, $units, (string) $warning, $haul, $driverName,
            )));
        }

        if ($load->threshold_notified_at === null && bccomp($units, $haul, 8) >= 0) {
            $load->update(['threshold_notified_at' => now()]);
            DB::afterCommit(fn () => Notification::send($recipients(), new TrailerLoadThresholdReached(
                $load->getKey(), $load->location->name, $units, $haul, $driverName,
            )));
        }
    }

    public function notifyWarehousePending(TrailerLoad $load): void
    {
        $load->loadMissing('location:id,name', 'committedDriver:id,name');
        if ($load->status !== TrailerLoadStatus::PendingWarehouseCount || $load->warehouse_notified_at !== null) {
            return;
        }
        $load->update(['warehouse_notified_at' => now()]);
        $notification = new WarehouseLoadPending(
            $load->getKey(), $load->location->name,
            $load->swapped_at?->toIso8601String() ?? now()->toIso8601String(),
            $load->committedDriver?->name ?? 'Unassigned', $load->operationalUnits(),
        );
        DB::afterCommit(fn () => Notification::send(
            $this->activeUsers([UserRole::WarehouseStaff, UserRole::OwnerAdmin]), $notification,
        ));
    }

    public function notifyWarehouseConfirmed(TrailerLoad $load): void
    {
        $load->loadMissing('location:id,name');
        if ($load->status !== TrailerLoadStatus::Completed || $load->warehouse_confirmed_notified_at !== null) {
            return;
        }
        $load->update(['warehouse_confirmed_notified_at' => now()]);
        $notification = new WarehouseCountConfirmed(
            $load->getKey(), $load->location->name, $load->operationalUnits(),
            (int) $load->warehouse_actual_count, (string) $load->warehouseVariance(),
            $load->warehouse_confirmed_at->toIso8601String(),
        );
        DB::afterCommit(fn () => Notification::send($this->activeUsers([UserRole::OwnerAdmin]), $notification));
    }

    public function notifyOpenRouteClaimed(TrailerLoad $load, User $driver): void
    {
        $notification = new RouteAssignmentNotice(
            $load->location->name, $load->getKey(), 'Open route claimed',
            "{$driver->name} claimed this active trailer/load.",
        );
        DB::afterCommit(fn () => Notification::send($this->activeUsers([UserRole::OwnerAdmin]), $notification));
    }

    public function notifyDedicatedDriverChanged(Location $location, ?int $oldDriverId, ?int $newDriverId, ?int $loadId = null): void
    {
        if ($oldDriverId === $newDriverId) {
            return;
        }
        $name = $location->name;
        DB::afterCommit(function () use ($name, $loadId, $oldDriverId, $newDriverId) {
            foreach ([[$oldDriverId, 'removed from'], [$newDriverId, 'assigned to']] as [$driverId, $action]) {
                if ($driverId === null) {
                    continue;
                }
                $driver = User::query()->whereKey($driverId)->where('role', UserRole::Driver->value)
                    ->where('is_active', true)->first();
                if ($driver) {
                    $scope = $loadId === null ? 'dedicated route configuration for future loads' : "trailer/load #{$loadId}";
                    $driver->notify(new RouteAssignmentNotice($name, $loadId, 'Driver assignment changed',
                        "You were {$action} the {$scope}."));
                }
            }
        });
    }

    private function loadRecipients(TrailerLoad $load)
    {
        $users = $this->activeUsers([UserRole::OwnerAdmin]);
        $driverId = $load->committed_driver_id ?? ($load->location->route_type === LocationRouteType::Dedicated
            ? $load->location->dedicated_driver_id : null);
        if ($driverId !== null) {
            return $users->merge(User::query()->whereKey($driverId)->where('role', UserRole::Driver->value)
                ->where('is_active', true)->get())->unique('id');
        }
        if ($load->location->route_type === LocationRouteType::Open) {
            return $users->merge($this->activeUsers([UserRole::Driver]))->unique('id');
        }

        return $users;
    }

    private function activeUsers(array $roles)
    {
        return User::query()->whereIn('role', array_map(fn (UserRole $role) => $role->value, $roles))
            ->where('is_active', true)->get();
    }
}
