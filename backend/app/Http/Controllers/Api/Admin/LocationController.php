<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\LocationRouteType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreLocationRequest;
use App\Http\Requests\Admin\UpdateActiveStatusRequest;
use App\Http\Requests\Admin\UpdateLocationRequest;
use App\Http\Resources\LocationResource;
use App\Models\Location;
use App\Services\LocationPriceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class LocationController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $locations = Location::query()
            ->with('dedicatedDriver:id,name,email,is_active')
            ->withCount([
                'terminals',
                'terminals as active_terminals_count' => fn ($query) => $query->where('is_active', true),
            ])
            ->orderBy('name')
            ->get();

        return LocationResource::collection($locations);
    }

    public function store(
        StoreLocationRequest $request,
        LocationPriceService $priceService,
    ): JsonResponse {
        $data = $request->validated();

        if ($data['route_type'] === LocationRouteType::Open->value) {
            $data['dedicated_driver_id'] = null;
        }

        $location = DB::transaction(function () use ($data, $request, $priceService) {
            $location = Location::query()->create($data);
            $priceService->createInitial($location, $request->user());

            return $location;
        }, 3);

        return (new LocationResource($this->loadLocation($location)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Location $location): LocationResource
    {
        return new LocationResource($this->loadLocation($location));
    }

    public function update(
        UpdateLocationRequest $request,
        Location $location,
        LocationPriceService $priceService,
    ): LocationResource {
        $data = $request->validated();
        $location = DB::transaction(function () use ($data, $location, $request, $priceService) {
            $lockedLocation = Location::query()
                ->whereKey($location->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $routeType = $data['route_type'] ?? $lockedLocation->route_type->value;

            if ($routeType === LocationRouteType::Open->value) {
                $data['dedicated_driver_id'] = null;
            }

            $newUnitPrice = $data['unit_price'] ?? null;
            unset($data['unit_price']);

            if ($newUnitPrice !== null
                && bccomp((string) $newUnitPrice, (string) $lockedLocation->unit_price, 2) !== 0) {
                $priceService->changeCurrentPrice(
                    $lockedLocation,
                    (string) $newUnitPrice,
                    $request->user(),
                );
            }

            $lockedLocation->update($data);

            return $lockedLocation;
        }, 3);

        return new LocationResource($this->loadLocation($location));
    }

    public function updateStatus(
        UpdateActiveStatusRequest $request,
        Location $location,
    ): LocationResource {
        $location->update($request->validated());

        return new LocationResource($this->loadLocation($location));
    }

    private function loadLocation(Location $location): Location
    {
        return $location->refresh()
            ->load(['dedicatedDriver:id,name,email,is_active', 'terminals'])
            ->loadCount([
                'terminals',
                'terminals as active_terminals_count' => fn ($query) => $query->where('is_active', true),
            ]);
    }
}
