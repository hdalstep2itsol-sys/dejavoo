<?php

namespace App\Services;

use App\Enums\DriverCommitmentSource;
use App\Enums\LocationRouteType;
use App\Enums\TrailerLoadStatus;
use App\Enums\UserRole;
use App\Exceptions\TrailerLoadCommitmentException;
use App\Models\TrailerLoad;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TrailerLoadCommitmentService
{
    public function claim(TrailerLoad $load, User $driver): TrailerLoad
    {
        return DB::transaction(function () use ($load, $driver) {
            $lockedLoad = $this->lockedLoad($load);

            if ($driver->role !== UserRole::Driver) {
                throw new TrailerLoadCommitmentException('Only a Driver user can claim a route.');
            }

            if ($lockedLoad->status !== TrailerLoadStatus::Active) {
                throw new TrailerLoadCommitmentException('Only an active trailer/load can be claimed.');
            }

            if ($lockedLoad->location->route_type !== LocationRouteType::Open) {
                throw new TrailerLoadCommitmentException('Dedicated routes cannot be claimed.');
            }

            if ($lockedLoad->committed_driver_id !== null) {
                throw new TrailerLoadCommitmentException('This route has already been claimed.');
            }

            $lockedLoad->update([
                'committed_driver_id' => $driver->getKey(),
                'commitment_source' => DriverCommitmentSource::OpenClaim,
                'committed_at' => now(),
                'committed_by_user_id' => $driver->getKey(),
            ]);

            return $this->loadRelations($lockedLoad);
        }, 3);
    }

    public function assign(TrailerLoad $load, User $driver, User $performedBy): TrailerLoad
    {
        if ($driver->role !== UserRole::Driver) {
            throw new TrailerLoadCommitmentException('Only a Driver user can be committed to a load.');
        }

        return DB::transaction(function () use ($load, $driver, $performedBy) {
            $lockedLoad = $this->lockedLoad($load);

            if ($lockedLoad->status !== TrailerLoadStatus::Active) {
                throw new TrailerLoadCommitmentException('Only an active trailer/load can receive a commitment.');
            }

            $source = $lockedLoad->location->route_type === LocationRouteType::Dedicated
                ? DriverCommitmentSource::Dedicated
                : DriverCommitmentSource::OpenClaim;

            $lockedLoad->update([
                'committed_driver_id' => $driver->getKey(),
                'commitment_source' => $source,
                'committed_at' => now(),
                'committed_by_user_id' => $performedBy->getKey(),
            ]);

            return $this->loadRelations($lockedLoad);
        }, 3);
    }

    public function remove(TrailerLoad $load): TrailerLoad
    {
        return DB::transaction(function () use ($load) {
            $lockedLoad = $this->lockedLoad($load);

            if ($lockedLoad->status !== TrailerLoadStatus::Active) {
                throw new TrailerLoadCommitmentException('Only an active trailer/load commitment can be removed.');
            }

            $lockedLoad->update([
                'committed_driver_id' => null,
                'commitment_source' => null,
                'committed_at' => null,
                'committed_by_user_id' => null,
            ]);

            return $this->loadRelations($lockedLoad);
        }, 3);
    }

    private function lockedLoad(TrailerLoad $load): TrailerLoad
    {
        return TrailerLoad::query()
            ->with('location')
            ->whereKey($load->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function loadRelations(TrailerLoad $load): TrailerLoad
    {
        return $load->refresh()->load([
            'location.dedicatedDriver:id,name,email',
            'createdBy:id,name,email',
            'committedDriver:id,name,email',
            'committedBy:id,name,email',
            'swappedBy:id,name,email',
            'warehouseConfirmedBy:id,name,email',
        ]);
    }
}
