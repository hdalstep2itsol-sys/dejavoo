<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreTerminalRequest;
use App\Http\Requests\Admin\UpdateActiveStatusRequest;
use App\Http\Requests\Admin\UpdateTerminalRequest;
use App\Http\Resources\DejavooTerminalResource;
use App\Models\DejavooTerminal;
use App\Models\Location;
use Illuminate\Http\JsonResponse;

class DejavooTerminalController extends Controller
{
    public function store(
        StoreTerminalRequest $request,
        Location $location,
    ): JsonResponse {
        $terminal = $location->terminals()->create($request->validated());

        return (new DejavooTerminalResource($terminal))
            ->response()
            ->setStatusCode(201);
    }

    public function update(
        UpdateTerminalRequest $request,
        Location $location,
        DejavooTerminal $terminal,
    ): DejavooTerminalResource {
        $terminal->update($request->validated());

        return new DejavooTerminalResource($terminal->refresh());
    }

    public function updateStatus(
        UpdateActiveStatusRequest $request,
        Location $location,
        DejavooTerminal $terminal,
    ): DejavooTerminalResource {
        $terminal->update($request->validated());

        return new DejavooTerminalResource($terminal->refresh());
    }
}
