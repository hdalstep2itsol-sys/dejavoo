<?php

namespace App\Services;

use App\Enums\TrailerLoadStatus;
use App\Exceptions\TrailerLoadLifecycleException;
use App\Models\Location;
use App\Models\TrailerLoad;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TrailerSwapService
{
    public function __construct(private readonly TrailerLoadService $loadService) {}

    /**
     * @return array{swapped_load: TrailerLoad, replacement_load: TrailerLoad}
     */
    public function swap(TrailerLoad $load, User $driver): array
    {
        return DB::transaction(function () use ($load, $driver) {
            $location = Location::query()
                ->whereKey($load->location_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedLoad = TrailerLoad::query()
                ->whereKey($load->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedLoad->status !== TrailerLoadStatus::Active) {
                throw new TrailerLoadLifecycleException('Only an active trailer/load can be swapped.');
            }

            if ($lockedLoad->committed_driver_id === null) {
                throw new TrailerLoadLifecycleException('This route must be claimed before it can be swapped.');
            }

            if ($lockedLoad->committed_driver_id !== $driver->getKey()) {
                throw new TrailerLoadLifecycleException('Only the committed Driver can swap this trailer/load.');
            }

            $swappedAt = now();

            $lockedLoad->update([
                'status' => TrailerLoadStatus::PendingWarehouseCount,
                'swapped_at' => $swappedAt,
                'swapped_by_user_id' => $driver->getKey(),
            ]);

            $replacementLoad = $this->loadService->initialize(
                $location,
                $driver,
                $swappedAt->toIso8601String(),
            );

            return [
                'swapped_load' => $this->loadRelations($lockedLoad),
                'replacement_load' => $this->loadRelations($replacementLoad),
            ];
        }, 3);
    }

    private function loadRelations(TrailerLoad $load): TrailerLoad
    {
        return $load->refresh()->load([
            'location:id,name,route_type,dedicated_driver_id',
            'createdBy:id,name,email',
            'committedDriver:id,name,email',
            'committedBy:id,name,email',
            'swappedBy:id,name,email',
            'warehouseConfirmedBy:id,name,email',
        ]);
    }
}
