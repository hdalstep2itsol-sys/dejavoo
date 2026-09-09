<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TrailerLoadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'location_id' => $this->location_id,
            'status' => $this->status->value,
            'started_at' => $this->started_at?->toIso8601String(),
            'location' => $this->whenLoaded('location', fn () => [
                'id' => $this->location->id,
                'name' => $this->location->name,
                'route_type' => $this->location->route_type->value,
            ]),
            'created_by' => $this->whenLoaded('createdBy', fn () => [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
                'email' => $this->createdBy->email,
            ]),
            'committed_driver' => $this->whenLoaded(
                'committedDriver',
                fn () => $this->committedDriver
                    ? [
                        'id' => $this->committedDriver->id,
                        'name' => $this->committedDriver->name,
                        'email' => $this->committedDriver->email,
                    ]
                    : null,
            ),
            'commitment_source' => $this->commitment_source?->value,
            'committed_at' => $this->committed_at?->toIso8601String(),
            'committed_by' => $this->whenLoaded(
                'committedBy',
                fn () => $this->committedBy
                    ? [
                        'id' => $this->committedBy->id,
                        'name' => $this->committedBy->name,
                        'email' => $this->committedBy->email,
                    ]
                    : null,
            ),
            'swapped_at' => $this->swapped_at?->toIso8601String(),
            'swapped_by' => $this->whenLoaded(
                'swappedBy',
                fn () => $this->swappedBy
                    ? [
                        'id' => $this->swappedBy->id,
                        'name' => $this->swappedBy->name,
                        'email' => $this->swappedBy->email,
                    ]
                    : null,
            ),
            'warehouse_actual_count' => $this->warehouse_actual_count,
            'warehouse_notes' => $this->warehouse_notes,
            'warehouse_confirmed_at' => $this->warehouse_confirmed_at?->toIso8601String(),
            'warehouse_confirmed_by' => $this->whenLoaded(
                'warehouseConfirmedBy',
                fn () => $this->warehouseConfirmedBy
                    ? [
                        'id' => $this->warehouseConfirmedBy->id,
                        'name' => $this->warehouseConfirmedBy->name,
                        'email' => $this->warehouseConfirmedBy->email,
                    ]
                    : null,
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
