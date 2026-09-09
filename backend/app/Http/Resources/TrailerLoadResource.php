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
            'calculated_units' => $this->calculatedUnits(),
            'manual_adjustment_units' => $this->manualAdjustmentUnits(),
            'operational_units' => $this->operationalUnits(),
            'operational_status' => $this->whenLoaded(
                'location',
                fn () => $this->operationalStatus(),
            ),
            'progress_percentage' => $this->whenLoaded(
                'location',
                fn () => $this->progressPercentage(),
            ),
            'location' => $this->whenLoaded('location', fn () => [
                'id' => $this->location->id,
                'name' => $this->location->name,
                'route_type' => $this->location->route_type->value,
                'haul_threshold' => $this->location->haul_threshold,
            ]),
            'created_by' => $this->whenLoaded('createdBy', fn () => [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
                'email' => $this->createdBy->email,
                'is_active' => (bool) $this->createdBy->is_active,
            ]),
            'committed_driver' => $this->whenLoaded(
                'committedDriver',
                fn () => $this->committedDriver
                    ? [
                        'id' => $this->committedDriver->id,
                        'name' => $this->committedDriver->name,
                        'email' => $this->committedDriver->email,
                        'is_active' => (bool) $this->committedDriver->is_active,
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
                        'is_active' => (bool) $this->committedBy->is_active,
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
                        'is_active' => (bool) $this->swappedBy->is_active,
                    ]
                    : null,
            ),
            'warehouse_actual_count' => $this->warehouse_actual_count,
            'warehouse_variance' => $this->warehouseVariance(),
            'warehouse_notes' => $this->warehouse_notes,
            'warehouse_confirmed_at' => $this->warehouse_confirmed_at?->toIso8601String(),
            'warehouse_confirmed_by' => $this->whenLoaded(
                'warehouseConfirmedBy',
                fn () => $this->warehouseConfirmedBy
                    ? [
                        'id' => $this->warehouseConfirmedBy->id,
                        'name' => $this->warehouseConfirmedBy->name,
                        'email' => $this->warehouseConfirmedBy->email,
                        'is_active' => (bool) $this->warehouseConfirmedBy->is_active,
                    ]
                    : null,
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
