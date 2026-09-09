<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignTrailerLoadDriverRequest;
use App\Http\Resources\TrailerLoadResource;
use App\Models\Location;
use App\Models\TrailerLoad;
use App\Models\User;
use App\Services\TrailerLoadCommitmentService;

class TrailerLoadCommitmentController extends Controller
{
    public function show(Location $location, TrailerLoad $trailerLoad): TrailerLoadResource
    {
        return new TrailerLoadResource($this->loadRelations($trailerLoad));
    }

    public function update(
        AssignTrailerLoadDriverRequest $request,
        Location $location,
        TrailerLoad $trailerLoad,
        TrailerLoadCommitmentService $service,
    ): TrailerLoadResource {
        $driver = User::query()->findOrFail($request->validated('driver_id'));

        return new TrailerLoadResource(
            $service->assign($trailerLoad, $driver, $request->user()),
        );
    }

    public function destroy(
        Location $location,
        TrailerLoad $trailerLoad,
        TrailerLoadCommitmentService $service,
    ): TrailerLoadResource {
        return new TrailerLoadResource($service->remove($trailerLoad));
    }

    private function loadRelations(TrailerLoad $load): TrailerLoad
    {
        return $load->load([
            'location:id,name,haul_threshold,route_type,dedicated_driver_id',
            'createdBy:id,name,email,is_active',
            'committedDriver:id,name,email,is_active',
            'committedBy:id,name,email,is_active',
            'swappedBy:id,name,email,is_active',
            'warehouseConfirmedBy:id,name,email,is_active',
        ])->loadUnitTotals();
    }
}
