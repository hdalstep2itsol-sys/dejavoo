<?php

namespace App\Models;

use App\Enums\NormalizedTransactionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NormalizedTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'location_id',
        'trailer_load_id',
        'dejavoo_terminal_id',
        'source',
        'external_transaction_id',
        'transaction_type',
        'business_amount',
        'unit_price_snapshot',
        'unit_delta',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'transaction_type' => NormalizedTransactionType::class,
            'business_amount' => 'decimal:2',
            'unit_price_snapshot' => 'decimal:2',
            'unit_delta' => 'decimal:8',
            'occurred_at' => 'datetime',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function trailerLoad(): BelongsTo
    {
        return $this->belongsTo(TrailerLoad::class);
    }

    public function dejavooTerminal(): BelongsTo
    {
        return $this->belongsTo(DejavooTerminal::class);
    }
}
