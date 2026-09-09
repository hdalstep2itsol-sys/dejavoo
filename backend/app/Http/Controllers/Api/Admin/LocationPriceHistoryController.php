<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\LocationPriceHistoryResource;
use App\Models\Location;
use App\Services\LocationPriceService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LocationPriceHistoryController extends Controller
{
    public function index(
        Location $location,
        LocationPriceService $priceService,
    ): AnonymousResourceCollection {
        if (! $location->priceHistory()->exists()) {
            $priceService->ensureHistory($location);
        }

        return LocationPriceHistoryResource::collection(
            $location->priceHistory()
                ->with('createdBy:id,name')
                ->orderByDesc('effective_from')
                ->orderByDesc('id')
                ->get(),
        );
    }
}
