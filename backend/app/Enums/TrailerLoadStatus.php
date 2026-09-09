<?php

namespace App\Enums;

enum TrailerLoadStatus: string
{
    case Active = 'active';
    case PendingWarehouseCount = 'pending_warehouse_count';
    case Completed = 'completed';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
