<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\DriverResource;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DriverController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $drivers = User::query()
            ->where('role', UserRole::Driver->value)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return DriverResource::collection($drivers);
    }
}
