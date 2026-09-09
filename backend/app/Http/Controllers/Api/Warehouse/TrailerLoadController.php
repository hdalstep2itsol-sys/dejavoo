<?php

namespace App\Http\Controllers\Api\Warehouse;

use App\Enums\TrailerLoadStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Warehouse\ConfirmTrailerLoadRequest;
use App\Http\Resources\TrailerLoadResource;
use App\Models\TrailerLoad;
use App\Services\WarehouseConfirmationService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TrailerLoadController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $loads = TrailerLoad::query()
            ->where('status', TrailerLoadStatus::PendingWarehouseCount->value)
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

        return new TrailerLoadResource($trailerLoad->load($this->relations()));
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
            'location:id,name,route_type,dedicated_driver_id',
            'createdBy:id,name,email',
            'committedDriver:id,name,email',
            'committedBy:id,name,email',
            'swappedBy:id,name,email',
            'warehouseConfirmedBy:id,name,email',
        ];
    }
}
