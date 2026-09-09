<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LocationPriceHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'location_id' => $this->location_id,
            'unit_price' => $this->unit_price,
            'effective_from' => $this->effective_from?->toIso8601String(),
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy
                ? [
                    'id' => $this->createdBy->id,
                    'name' => $this->createdBy->name,
                ]
                : null),
            'created_at' => $this->created_at,
        ];
    }
}
