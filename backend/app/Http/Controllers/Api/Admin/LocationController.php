<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\LocationRouteType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreLocationRequest;
use App\Http\Requests\Admin\UpdateActiveStatusRequest;
use App\Http\Requests\Admin\UpdateLocationRequest;
use App\Http\Resources\LocationResource;
use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LocationController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $locations = Location::query()
            ->with('dedicatedDriver:id,name,email')
            ->withCount([
                'terminals',
                'terminals as active_terminals_count' => fn ($query) => $query->where('is_active', true),
            ])
            ->orderBy('name')
            ->get();

        return LocationResource::collection($locations);
    }

    public function store(StoreLocationRequest $request): JsonResponse
    {
        $data = $request->validated();

        if ($data['route_type'] === LocationRouteType::Open->value) {
            $data['dedicated_driver_id'] = null;
        }

        $location = Location::query()->create($data);

        return (new LocationResource($this->loadLocation($location)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Location $location): LocationResource
    {
        return new LocationResource($this->loadLocation($location));
    }

    public function update(UpdateLocationRequest $request, Location $location): LocationResource
    {
        $data = $request->validated();
        $routeType = $data['route_type'] ?? $location->route_type->value;

        if ($routeType === LocationRouteType::Open->value) {
            $data['dedicated_driver_id'] = null;
        }

        $location->update($data);

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
            ->load(['dedicatedDriver:id,name,email', 'terminals'])
            ->loadCount([
                'terminals',
                'terminals as active_terminals_count' => fn ($query) => $query->where('is_active', true),
            ]);
    }
}
