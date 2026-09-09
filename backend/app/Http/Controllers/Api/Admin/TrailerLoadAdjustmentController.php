<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreTrailerLoadAdjustmentRequest;
use App\Http\Resources\TrailerLoadAdjustmentResource;
use App\Models\Location;
use App\Models\TrailerLoad;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
    ): JsonResponse {
        $adjustment = $trailerLoad->adjustments()->create([
            ...$request->validated(),
            'created_by_user_id' => $request->user()->getKey(),
        ]);

        return (new TrailerLoadAdjustmentResource(
            $adjustment->load('createdBy:id,name'),
        ))->response()->setStatusCode(201);
    }
}
