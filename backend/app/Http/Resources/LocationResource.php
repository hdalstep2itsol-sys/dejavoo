<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'unit_price' => $this->unit_price,
            'haul_threshold' => $this->haul_threshold,
            'route_type' => $this->route_type->value,
            'dedicated_driver' => $this->whenLoaded(
                'dedicatedDriver',
                fn () => $this->dedicatedDriver
                    ? new DriverResource($this->dedicatedDriver)
                    : null,
            ),
            'is_active' => $this->is_active,
            'terminal_summary' => $this->whenCounted('terminals', fn () => [
                'total' => $this->terminals_count,
                'active' => $this->active_terminals_count,
                'inactive' => $this->terminals_count - $this->active_terminals_count,
            ]),
            'terminals' => DejavooTerminalResource::collection($this->whenLoaded('terminals')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
