<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreTrailerLoadAdjustmentRequest;
use App\Http\Resources\TrailerLoadAdjustmentResource;
use App\Models\Location;
use App\Models\TrailerLoad;
use App\Services\OperationalNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class TrailerLoadAdjustmentController extends Controller
{
    public function index(
        Location $location,
        TrailerLoad $trailerLoad,
    ): AnonymousResourceCollection {
        return TrailerLoadAdjustmentResource::collection(
            $trailerLoad->adjustments()
                ->with('createdBy:id,name')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get(),
        );
    }

    public function store(
        StoreTrailerLoadAdjustmentRequest $request,
        Location $location,
        TrailerLoad $trailerLoad,
        OperationalNotificationService $notifications,
    ): JsonResponse {
        $adjustment = DB::transaction(function () use ($request, $trailerLoad, $notifications) {
            $adjustment = $trailerLoad->adjustments()->create([
                ...$request->validated(),
                'created_by_user_id' => $request->user()->getKey(),
            ]);

            $notifications->notifyThresholdIfReached($trailerLoad);

            return $adjustment;
        }, 3);

        return (new TrailerLoadAdjustmentResource(
            $adjustment->load('createdBy:id,name'),
        ))->response()->setStatusCode(201);
    }
}
