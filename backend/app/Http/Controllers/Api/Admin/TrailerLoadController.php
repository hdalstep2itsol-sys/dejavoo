<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\TrailerLoadStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\InitializeTrailerLoadRequest;
use App\Http\Resources\TrailerLoadResource;
use App\Models\Location;
use App\Models\TrailerLoad;
use App\Services\TrailerLoadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TrailerLoadController extends Controller
{
    public function index(Location $location): AnonymousResourceCollection
    {
        $loads = $location->trailerLoads()
            ->with('createdBy:id,name,email')
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->get();

        return TrailerLoadResource::collection($loads);
    }

    public function current(Location $location): TrailerLoadResource|JsonResponse
    {
        $load = $location->trailerLoads()
            ->where('status', TrailerLoadStatus::Active->value)
            ->with(['location:id,name', 'createdBy:id,name,email'])
            ->first();

        if (! $load) {
            return response()->json(['data' => null]);
        }

        return new TrailerLoadResource($load);
    }

    public function store(
        InitializeTrailerLoadRequest $request,
        Location $location,
        TrailerLoadService $service,
    ): JsonResponse {
        $load = $service->initialize(
            $location,
            $request->user(),
            $request->validated('started_at'),
        );

        return (new TrailerLoadResource(
            $load->load(['location:id,name', 'createdBy:id,name,email']),
        ))->response()->setStatusCode(201);
    }

    public function show(Location $location, TrailerLoad $trailerLoad): TrailerLoadResource
    {
        return new TrailerLoadResource(
            $trailerLoad->load(['location:id,name', 'createdBy:id,name,email']),
        );
    }
}
