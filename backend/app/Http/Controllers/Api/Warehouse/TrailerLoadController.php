<?php

namespace App\Http\Controllers\Api\Warehouse;

use App\Enums\TrailerLoadStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Warehouse\ConfirmTrailerLoadRequest;
use App\Http\Resources\TrailerLoadResource;
use App\Models\TrailerLoad;
use App\Services\WarehouseConfirmationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TrailerLoadController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        $pending = TrailerLoad::query()
            ->where('status', TrailerLoadStatus::PendingWarehouseCount->value)
            ->withUnitTotals()
            ->with($this->relations())
            ->orderBy('swapped_at')
            ->orderBy('id')
            ->get();

        $recentConfirmed = TrailerLoad::query()
            ->where('status', TrailerLoadStatus::Completed->value)
            ->where('warehouse_confirmed_by_user_id', $request->user()->getKey())
            ->withUnitTotals()
            ->with($this->relations())
            ->orderByDesc('warehouse_confirmed_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return response()->json([
            'data' => [
                'summary' => [
                    'awaiting_warehouse_count' => $pending->count(),
                ],
                'pending' => TrailerLoadResource::collection($pending)->resolve($request),
                'recent_confirmed' => TrailerLoadResource::collection($recentConfirmed)->resolve($request),
            ],
        ]);
    }

    public function index(): AnonymousResourceCollection
    {
        $loads = TrailerLoad::query()
            ->where('status', TrailerLoadStatus::PendingWarehouseCount->value)
            ->withUnitTotals()
            ->with($this->relations())
            ->orderBy('swapped_at')
            ->orderBy('id')
            ->get();

        return TrailerLoadResource::collection($loads);
    }

    public function show(TrailerLoad $trailerLoad): TrailerLoadResource
    {
        abort_unless(
            $trailerLoad->status === TrailerLoadStatus::PendingWarehouseCount,
            404,
        );

        return new TrailerLoadResource(
            $trailerLoad->load($this->relations())->loadUnitTotals(),
        );
    }

    public function confirm(
        ConfirmTrailerLoadRequest $request,
        TrailerLoad $trailerLoad,
        WarehouseConfirmationService $service,
    ): TrailerLoadResource {
        $load = $service->confirm(
            $trailerLoad,
            $request->user(),
            $request->integer('actual_count'),
            $request->validated('notes'),
        );

        return new TrailerLoadResource($load);
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
