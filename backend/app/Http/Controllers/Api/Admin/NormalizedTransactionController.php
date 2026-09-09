<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\NormalizedTransactionResource;
use App\Models\Location;
use App\Models\TrailerLoad;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class NormalizedTransactionController extends Controller
{
    public function index(
        Location $location,
        TrailerLoad $trailerLoad,
    ): AnonymousResourceCollection {
        return NormalizedTransactionResource::collection(
            $trailerLoad->normalizedTransactions()
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->get(),
        );
    }
}
