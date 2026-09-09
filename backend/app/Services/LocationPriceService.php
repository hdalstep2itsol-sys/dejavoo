<?php

namespace App\Services;

use App\Exceptions\NormalizedTransactionException;
use App\Models\Location;
use App\Models\LocationPriceHistory;
use App\Models\User;
use Carbon\CarbonInterface;

class LocationPriceService
{
    public function createInitial(Location $location, User $createdBy): LocationPriceHistory
    {
        return $location->priceHistory()->create([
            'unit_price' => $location->unit_price,
            'effective_from' => $location->created_at ?? now(),
            'created_by_user_id' => $createdBy->getKey(),
        ]);
    }

    public function ensureHistory(Location $location): LocationPriceHistory
    {
        return $location->priceHistory()->firstOrCreate(
            ['effective_from' => $location->created_at ?? now()],
            [
                'unit_price' => $location->unit_price,
                'created_by_user_id' => null,
            ],
        );
    }

    public function changeCurrentPrice(
        Location $location,
        string $unitPrice,
        User $createdBy,
    ): LocationPriceHistory {
        $latest = $location->priceHistory()
            ->orderByDesc('effective_from')
            ->lockForUpdate()
            ->first();
        $effectiveFrom = now();

        if ($latest && $effectiveFrom->lessThanOrEqualTo($latest->effective_from)) {
            $effectiveFrom = $latest->effective_from->copy()->addMicrosecond();
        }

        $history = $location->priceHistory()->create([
            'unit_price' => $unitPrice,
            'effective_from' => $effectiveFrom,
            'created_by_user_id' => $createdBy->getKey(),
        ]);

        $location->update(['unit_price' => $unitPrice]);

        return $history;
    }

    public function resolveAt(
        Location $location,
        CarbonInterface $occurredAt,
    ): LocationPriceHistory {
        $price = $location->priceHistory()
            ->where('effective_from', '<=', $occurredAt)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        if (! $price) {
            throw new NormalizedTransactionException(
                'No location price covers the transaction timestamp.',
            );
        }

        return $price;
    }
}
