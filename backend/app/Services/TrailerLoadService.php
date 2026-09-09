<?php

namespace App\Services;

use App\Enums\DriverCommitmentSource;
use App\Enums\LocationRouteType;
use App\Enums\TrailerLoadStatus;
use App\Exceptions\ActiveTrailerLoadExistsException;
use App\Models\Location;
use App\Models\TrailerLoad;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class TrailerLoadService
{
    public function initialize(Location $location, User $createdBy, string $startedAt): TrailerLoad
    {
        try {
            return DB::transaction(function () use ($location, $createdBy, $startedAt) {
                $lockedLocation = Location::query()
                    ->whereKey($location->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($this->activeLoadExists($location)) {
                    throw new ActiveTrailerLoadExistsException;
                }

                $commitment = $lockedLocation->route_type === LocationRouteType::Dedicated
                    ? [
                        'committed_driver_id' => $lockedLocation->dedicated_driver_id,
                        'commitment_source' => DriverCommitmentSource::Dedicated,
                        'committed_at' => now(),
                        'committed_by_user_id' => $createdBy->getKey(),
                    ]
                    : [];

                return TrailerLoad::query()->create([
                    'location_id' => $location->getKey(),
                    'status' => TrailerLoadStatus::Active,
                    'started_at' => $startedAt,
                    'created_by_user_id' => $createdBy->getKey(),
                    ...$commitment,
                ]);
            }, 3);
        } catch (QueryException $exception) {
            // The generated unique guard is the final defense if two requests
            // race beyond the application-level existence check.
            if ($this->activeLoadExists($location)) {
                throw new ActiveTrailerLoadExistsException;
            }

            throw $exception;
        }
    }

    private function activeLoadExists(Location $location): bool
    {
        return TrailerLoad::query()
            ->where('location_id', $location->getKey())
            ->where('status', TrailerLoadStatus::Active->value)
            ->exists();
    }
}
