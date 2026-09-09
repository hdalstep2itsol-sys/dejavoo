<?php

namespace App\Models;

use App\Enums\LocationRouteType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'unit_price',
        'haul_threshold',
        'route_type',
        'dedicated_driver_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'haul_threshold' => 'decimal:2',
            'route_type' => LocationRouteType::class,
            'is_active' => 'boolean',
        ];
    }

    public function dedicatedDriver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dedicated_driver_id');
    }

    public function terminals(): HasMany
    {
        return $this->hasMany(DejavooTerminal::class);
    }
}
