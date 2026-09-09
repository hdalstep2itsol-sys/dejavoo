<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TrailerLoadReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $driver = $this->swappedBy ?? $this->committedDriver;

        return [
            'id' => $this->id,
            'location' => [
                'id' => $this->location->id,
                'name' => $this->location->name,
            ],
            'status' => $this->status->value,
            'started_at' => $this->started_at?->toIso8601String(),
            'swapped_at' => $this->swapped_at?->toIso8601String(),
            'driver' => $driver
                ? [
                    'id' => $driver->id,
                    'name' => $driver->name,
                    'is_active' => (bool) $driver->is_active,
                ]
                : null,
            'route_type' => $this->location->route_type->value,
            'calculated_units' => $this->calculatedUnits(),
            'manual_adjustment_units' => $this->manualAdjustmentUnits(),
            'operational_units' => $this->operationalUnits(),
            'warehouse_actual_count' => $this->warehouse_actual_count,
            'warehouse_variance' => $this->warehouseVariance(),
            'warehouse_confirmed_at' => $this->warehouse_confirmed_at?->toIso8601String(),
        ];
    }
}
