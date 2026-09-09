<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\LocationRouteType;
use App\Enums\TrailerLoadStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LoadHistoryRequest;
use App\Http\Resources\TrailerLoadResource;
use App\Models\Location;
use App\Models\TrailerLoad;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        $locations = Location::query()
            ->where('is_active', true)
            ->with('dedicatedDriver:id,name,email,is_active')
            ->orderBy('name')
            ->get();

        $activeLoads = $this->loadQuery()
            ->where('status', TrailerLoadStatus::Active->value)
            ->whereIn('location_id', $locations->pluck('id'))
            ->orderBy('started_at')
            ->get();

        $activeByLocation = $activeLoads->keyBy('location_id');

        return response()->json([
            'data' => [
                'summary' => [
                    'active_locations' => $locations->count(),
                    'ready_loads' => $activeLoads
                        ->filter(fn (TrailerLoad $load) => $load->operationalStatus() === 'ready')
                        ->count(),
                    'awaiting_warehouse_count' => TrailerLoad::query()
                        ->where('status', TrailerLoadStatus::PendingWarehouseCount->value)
                        ->count(),
                    'open_unclaimed_active_loads' => $activeLoads
                        ->filter(fn (TrailerLoad $load) => $load->location->route_type === LocationRouteType::Open
                            && $load->committed_driver_id === null)
                        ->count(),
                ],
                'locations' => $locations->map(function (Location $location) use ($activeByLocation, $request) {
                    $load = $activeByLocation->get($location->getKey());

                    return [
                        'id' => $location->id,
                        'name' => $location->name,
                        'route_type' => $location->route_type->value,
                        'haul_threshold' => $location->haul_threshold,
                        'dedicated_driver' => $location->dedicatedDriver
                            ? [
                                'id' => $location->dedicatedDriver->id,
                                'name' => $location->dedicatedDriver->name,
                                'email' => $location->dedicatedDriver->email,
                                'is_active' => (bool) $location->dedicatedDriver->is_active,
                            ]
                            : null,
                        'active_load' => $load
                            ? (new TrailerLoadResource($load))->resolve($request)
                            : null,
                    ];
                })->values(),
            ],
        ]);
    }

    public function routes(Request $request): JsonResponse
    {
        $loads = $this->loadQuery()
            ->where('status', TrailerLoadStatus::Active->value)
            ->orderBy('started_at')
            ->get();

        return response()->json([
            'data' => TrailerLoadResource::collection($loads)->resolve($request),
        ]);
    }

    public function pendingWarehouse(Request $request): JsonResponse
    {
        $loads = $this->loadQuery()
            ->where('status', TrailerLoadStatus::PendingWarehouseCount->value)
            ->orderBy('swapped_at')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => TrailerLoadResource::collection($loads)->resolve($request),
        ]);
    }

    public function history(LoadHistoryRequest $request): JsonResponse
    {
        $query = $this->loadQuery()
            ->whereIn('status', [
                TrailerLoadStatus::PendingWarehouseCount->value,
                TrailerLoadStatus::Completed->value,
            ]);

        if ($request->filled('location_id')) {
            $query->where('location_id', $request->integer('location_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->validated('status'));
        }

        $loads = $query
            ->orderByDesc('swapped_at')
            ->orderByDesc('id')
            ->get();

        $locations = Location::query()
            ->whereHas('trailerLoads', fn (Builder $loadQuery) => $loadQuery->whereIn('status', [
                TrailerLoadStatus::PendingWarehouseCount->value,
                TrailerLoadStatus::Completed->value,
            ]))
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'data' => [
                'loads' => TrailerLoadResource::collection($loads)->resolve($request),
                'locations' => $locations,
            ],
        ]);
    }

    private function loadQuery(): Builder
    {
        return TrailerLoad::query()
            ->withUnitTotals()
            ->with([
                'location:id,name,haul_threshold,route_type,dedicated_driver_id,is_active',
                'location.dedicatedDriver:id,name,email,is_active',
                'createdBy:id,name,email,is_active',
                'committedDriver:id,name,email,is_active',
                'committedBy:id,name,email,is_active',
                'swappedBy:id,name,email,is_active',
                'warehouseConfirmedBy:id,name,email,is_active',
            ]);
    }
}
