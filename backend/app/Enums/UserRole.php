<?php

namespace App\Enums;

enum UserRole: string
{
    case OwnerAdmin = 'owner_admin';
    case Driver = 'driver';
    case WarehouseStaff = 'warehouse_staff';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
