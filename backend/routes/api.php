<?php

use App\Http\Controllers\Api\Admin\DejavooTerminalController;
use App\Http\Controllers\Api\Admin\DriverController;
use App\Http\Controllers\Api\Admin\LocationController;
use App\Http\Controllers\Api\Admin\TrailerLoadController;
use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'dejavoo-backend',
    ]);
});

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('auth.login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/user', [AuthController::class, 'current'])->name('auth.user');
        Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');
    });
});

Route::prefix('admin')
    ->middleware(['auth:sanctum', 'role:owner_admin'])
    ->scopeBindings()
    ->group(function () {
        Route::get('/drivers', [DriverController::class, 'index'])->name('admin.drivers.index');

        Route::apiResource('locations', LocationController::class)
            ->only(['index', 'store', 'show', 'update']);
        Route::patch('/locations/{location}/status', [LocationController::class, 'updateStatus'])
            ->name('admin.locations.status');

        Route::post('/locations/{location}/terminals', [DejavooTerminalController::class, 'store'])
            ->name('admin.locations.terminals.store');
        Route::put('/locations/{location}/terminals/{terminal}', [DejavooTerminalController::class, 'update'])
            ->name('admin.locations.terminals.update');
        Route::patch('/locations/{location}/terminals/{terminal}/status', [DejavooTerminalController::class, 'updateStatus'])
            ->name('admin.locations.terminals.status');

        Route::get('/locations/{location}/trailer-loads/current', [TrailerLoadController::class, 'current'])
            ->name('admin.locations.trailer-loads.current');
        Route::get('/locations/{location}/trailer-loads', [TrailerLoadController::class, 'index'])
            ->name('admin.locations.trailer-loads.index');
        Route::post('/locations/{location}/trailer-loads', [TrailerLoadController::class, 'store'])
            ->name('admin.locations.trailer-loads.store');
        Route::get('/locations/{location}/trailer-loads/{trailerLoad}', [TrailerLoadController::class, 'show'])
            ->name('admin.locations.trailer-loads.show');
    });
