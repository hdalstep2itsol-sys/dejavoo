<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DejavooTerminal extends Model
{
    use HasFactory;

    protected $fillable = [
        'location_id',
        'tpn',
        'term_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function normalizedTransactions(): HasMany
    {
        return $this->hasMany(NormalizedTransaction::class);
    }
}
