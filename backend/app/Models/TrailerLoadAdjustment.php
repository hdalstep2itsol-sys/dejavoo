<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrailerLoadAdjustment extends Model
{
    use HasFactory;

    protected $fillable = [
        'trailer_load_id',
        'unit_delta',
        'reason',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'unit_delta' => 'decimal:8',
        ];
    }

    public function trailerLoad(): BelongsTo
    {
        return $this->belongsTo(TrailerLoad::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
