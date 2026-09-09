<?php

namespace App\Enums;

enum LocationRouteType: string
{
    case Open = 'open';
    case Dedicated = 'dedicated';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
