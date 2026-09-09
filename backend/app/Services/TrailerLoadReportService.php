<?php

namespace App\Services;

use App\Enums\TrailerLoadStatus;
use App\Models\TrailerLoad;
use Illuminate\Database\Eloquent\Builder;

class TrailerLoadReportService
{
    /**
     * @var array<string, string>
     */
    public const COLUMNS = [
        'location' => 'Location',
        'status' => 'Load Status',
        'started_at' => 'Started At',
        'swapped_at' => 'Swapped At',
        'driver' => 'Driver',
        'route_type' => 'Route Type',
        'calculated_units' => 'Calculated Units',
        'manual_adjustment_units' => 'Manual Adjustment Units',
        'operational_units' => 'Operational Units',
        'warehouse_actual_count' => 'Warehouse Actual Count',
        'variance' => 'Variance',
        'warehouse_confirmed_at' => 'Warehouse Confirmed At',
    ];

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<int, TrailerLoadStatus|string>|null  $allowedStatuses
     */
    public function query(array $filters = [], ?array $allowedStatuses = null): Builder
    {
        $query = TrailerLoad::query()
            ->withUnitTotals()
            ->with([
                'location:id,name,route_type,haul_threshold',
                'committedDriver:id,name,is_active',
                'swappedBy:id,name,is_active',
            ]);

        if ($allowedStatuses !== null) {
            $query->whereIn('status', array_map(
                fn (TrailerLoadStatus|string $status) => $status instanceof TrailerLoadStatus
                    ? $status->value
                    : $status,
                $allowedStatuses,
            ));
        }

        if (! empty($filters['location_id'])) {
            $query->where('location_id', $filters['location_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['start_date'])) {
            $query->whereDate('started_at', '>=', $filters['start_date']);
        }

        if (! empty($filters['end_date'])) {
            $query->whereDate('started_at', '<=', $filters['end_date']);
        }

        if (! empty($filters['driver_id'])) {
            $driverId = $filters['driver_id'];
            $query->where(function (Builder $driverQuery) use ($driverId) {
                $driverQuery
                    ->where('swapped_by_user_id', $driverId)
                    ->orWhere(function (Builder $activeQuery) use ($driverId) {
                        $activeQuery
                            ->where('status', TrailerLoadStatus::Active->value)
                            ->where('committed_driver_id', $driverId);
                    });
            });
        }

        return $query
            ->orderByDesc('started_at')
            ->orderByDesc('id');
    }

    /**
     * @return list<string>
     */
    public function headers(): array
    {
        return array_values(self::COLUMNS);
    }

    /**
     * @return list<string>
     */
    public function exportRow(TrailerLoad $load): array
    {
        $driver = $load->swappedBy ?? $load->committedDriver;

        return [
            $load->location->name,
            $load->status->value,
            $this->dateTime($load->started_at),
            $this->dateTime($load->swapped_at),
            $driver?->name ?? '',
            $load->location->route_type->value,
            $load->calculatedUnits(),
            $load->manualAdjustmentUnits(),
            $load->operationalUnits(),
            $load->warehouse_actual_count === null ? '' : (string) $load->warehouse_actual_count,
            $load->warehouseVariance() ?? '',
            $this->dateTime($load->warehouse_confirmed_at),
        ];
    }

    /**
     * @return list<string>
     */
    public function csvRow(TrailerLoad $load): array
    {
        $row = $this->exportRow($load);

        foreach ([0, 4] as $index) {
            if (preg_match('/^[=+\-@]/u', $row[$index]) === 1) {
                $row[$index] = "'".$row[$index];
            }
        }

        return $row;
    }

    private function dateTime(mixed $value): string
    {
        return $value?->format('Y-m-d H:i:s T') ?? '';
    }
}
