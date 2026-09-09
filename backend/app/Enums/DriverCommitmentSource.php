<?php

namespace App\Enums;

enum DriverCommitmentSource: string
{
    case Dedicated = 'dedicated';
    case OpenClaim = 'open_claim';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
