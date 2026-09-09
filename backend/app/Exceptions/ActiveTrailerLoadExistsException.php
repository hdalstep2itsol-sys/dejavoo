<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ActiveTrailerLoadExistsException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This location already has an active trailer/load.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => [
                'location_id' => [$this->getMessage()],
            ],
        ], 409);
    }
}
