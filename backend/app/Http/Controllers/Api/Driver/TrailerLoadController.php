<?php

namespace App\Http\Controllers\Api\Driver;

use App\Enums\LocationRouteType;
use App\Enums\TrailerLoadStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\TrailerLoadResource;
use App\Models\TrailerLoad;
use App\Services\TrailerLoadCommitmentService;
use App\Services\TrailerSwapService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrailerLoadController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $activeLoads = TrailerLoad::query()
            ->where('status', TrailerLoadStatus::Active->value)
            ->withUnitTotals()
            ->with($this->relations());

        $myRoutes = (clone $activeLoads)
            ->where('committed_driver_id', $request->user()->getKey())
            ->orderBy('started_at')
            ->get();

        $openRoutes = (clone $activeLoads)
            ->whereNull('committed_driver_id')
            ->whereHas('location', fn (Builder $query) => $query->where(
                'route_type',
                LocationRouteType::Open->value,
            ))
            ->orderBy('started_at')
            ->get();

        $history = TrailerLoad::query()
            ->where('swapped_by_user_id', $request->user()->getKey())
            ->whereIn('status', [
                TrailerLoadStatus::PendingWarehouseCount->value,
                TrailerLoadStatus::Completed->value,
            ])
            ->withUnitTotals()
            ->with($this->relations())
            ->orderByDesc('swapped_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => [
                'summary' => [
                    'my_active_routes' => $myRoutes->count(),
                    'open_routes' => $openRoutes->count(),
                    'ready_loads' => $myRoutes
                        ->filter(fn (TrailerLoad $load) => $load->operationalStatus() === 'ready')
                        ->count(),
                ],
                'my_routes' => TrailerLoadResource::collection($myRoutes)->resolve($request),
                'open_routes' => TrailerLoadResource::collection($openRoutes)->resolve($request),
                'history' => TrailerLoadResource::collection($history)->resolve($request),
            ],
        ]);
    }

    public function show(Request $request, TrailerLoad $trailerLoad): TrailerLoadResource
    {
        $load = $trailerLoad->load($this->relations())->loadUnitTotals();

        abort_unless($this->isRelevant($load, $request), 404);

        return new TrailerLoadResource($load);
    }

    public function claim(
        Request $request,
        TrailerLoad $trailerLoad,
        TrailerLoadCommitmentService $service,
    ): TrailerLoadResource {
        return new TrailerLoadResource(
            $service->claim($trailerLoad, $request->user()),
        );
    }

    public function swap(
        Request $request,
        TrailerLoad $trailerLoad,
        TrailerSwapService $service,
    ): JsonResponse {
        $result = $service->swap($trailerLoad, $request->user());

        return response()->json([
            'data' => [
                'swapped_load' => (new TrailerLoadResource($result['swapped_load']))->resolve($request),
                'replacement_load' => (new TrailerLoadResource($result['replacement_load']))->resolve($request),
            ],
        ]);
    }

    private function isRelevant(TrailerLoad $load, Request $request): bool
    {
        if ($load->status !== TrailerLoadStatus::Active) {
            return false;
        }

        if ($load->committed_driver_id === $request->user()->getKey()) {
            return true;
        }

        return $load->committed_driver_id === null
            && $load->location->route_type === LocationRouteType::Open;
    }

    /**
     * @return array<int, string>
     */
    private function relations(): array
    {
        return [
            'location:id,name,haul_threshold,route_type,dedicated_driver_id',
            'createdBy:id,name,email,is_active',
            'committedDriver:id,name,email,is_active',
            'committedBy:id,name,email,is_active',
            'swappedBy:id,name,email,is_active',
            'warehouseConfirmedBy:id,name,email,is_active',
        ];
    }
}
