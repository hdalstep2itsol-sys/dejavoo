<?php

namespace App\Models;

use App\Enums\IpospaysFeedEventStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IpospaysFeedEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider_event_id',
        'provider_transaction_id',
        'event_type',
        'sub_event_type',
        'request_type',
        'tpn',
        'term_id',
        'transaction_type',
        'business_amount',
        'base_amount',
        'occurred_at',
        'status',
        'error_code',
        'payload_fingerprint',
        'financial_fingerprint',
        'delivery_count',
        'last_received_at',
        'authenticated_at',
        'processed_at',
        'dejavoo_terminal_id',
        'location_id',
        'normalized_transaction_id',
    ];

    protected function casts(): array
    {
        return [
            'business_amount' => 'decimal:2',
            'base_amount' => 'decimal:2',
            'occurred_at' => 'datetime',
            'status' => IpospaysFeedEventStatus::class,
            'last_received_at' => 'datetime',
            'authenticated_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(DejavooTerminal::class, 'dejavoo_terminal_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function normalizedTransaction(): BelongsTo
    {
        return $this->belongsTo(NormalizedTransaction::class);
    }
}
