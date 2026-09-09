<?php

namespace App\Enums;

enum NormalizedTransactionType: string
{
    case Sale = 'sale';
    case Refund = 'refund';
    case Void = 'void';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function sign(): int
    {
        return $this === self::Sale ? 1 : -1;
    }
}
