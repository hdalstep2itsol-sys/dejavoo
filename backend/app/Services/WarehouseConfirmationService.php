<?php

namespace App\Services;

use App\Enums\TrailerLoadStatus;
use App\Exceptions\TrailerLoadLifecycleException;
use App\Models\TrailerLoad;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class WarehouseConfirmationService
{
    public function confirm(
        TrailerLoad $load,
        User $warehouseUser,
        int $actualCount,
        ?string $notes,
    ): TrailerLoad {
        return DB::transaction(function () use ($load, $warehouseUser, $actualCount, $notes) {
            $lockedLoad = TrailerLoad::query()
                ->whereKey($load->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedLoad->status !== TrailerLoadStatus::PendingWarehouseCount) {
                throw new TrailerLoadLifecycleException(
                    'Only a trailer/load pending warehouse count can be confirmed.',
                );
            }

            $lockedLoad->update([
                'status' => TrailerLoadStatus::Completed,
                'warehouse_actual_count' => $actualCount,
                'warehouse_notes' => $notes,
                'warehouse_confirmed_at' => now(),
                'warehouse_confirmed_by_user_id' => $warehouseUser->getKey(),
            ]);

            return $lockedLoad->refresh()->load([
                'location:id,name,haul_threshold,route_type,dedicated_driver_id',
                'createdBy:id,name,email,is_active',
                'committedDriver:id,name,email,is_active',
                'committedBy:id,name,email,is_active',
                'swappedBy:id,name,email,is_active',
                'warehouseConfirmedBy:id,name,email,is_active',
            ])->loadUnitTotals();
        }, 3);
    }
}
