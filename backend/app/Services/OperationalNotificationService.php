<?php

namespace App\Services;

use App\Enums\TrailerLoadStatus;
use App\Enums\UserRole;
use App\Models\TrailerLoad;
use App\Models\User;
use App\Notifications\TrailerLoadThresholdReached;
use App\Notifications\WarehouseLoadPending;
use Illuminate\Support\Facades\Notification;

class OperationalNotificationService
{
    public function notifyThresholdIfReached(TrailerLoad $load): void
    {
        $load = TrailerLoad::query()
            ->with('location:id,name,haul_threshold')
            ->whereKey($load->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        if ($load->status !== TrailerLoadStatus::Active
            || $load->threshold_notified_at !== null
            || bccomp($load->operationalUnits(), (string) $load->location->haul_threshold, 8) < 0) {
            return;
        }

        $load->update(['threshold_notified_at' => now()]);

        Notification::send(
            $this->activeUsers(UserRole::OwnerAdmin),
            new TrailerLoadThresholdReached(
                $load->getKey(),
                $load->location->name,
                $load->operationalUnits(),
                (string) $load->location->haul_threshold,
            ),
        );
    }

    public function notifyWarehousePending(TrailerLoad $load): void
    {
        $load->loadMissing('location:id,name');

        if ($load->status !== TrailerLoadStatus::PendingWarehouseCount
            || $load->warehouse_notified_at !== null) {
            return;
        }

        $load->update(['warehouse_notified_at' => now()]);

        Notification::send(
            $this->activeUsers(UserRole::WarehouseStaff),
            new WarehouseLoadPending(
                $load->getKey(),
                $load->location->name,
                $load->swapped_at?->toIso8601String() ?? now()->toIso8601String(),
            ),
        );
    }

    private function activeUsers(UserRole $role)
    {
        return User::query()
            ->where('role', $role->value)
            ->where('is_active', true)
            ->get();
    }
}
