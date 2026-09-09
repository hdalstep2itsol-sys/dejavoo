<?php

namespace App\Models;

use App\Enums\TrailerLoadStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrailerLoad extends Model
{
    use HasFactory;

    protected $fillable = [
        'location_id',
        'status',
        'started_at',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => TrailerLoadStatus::class,
            'started_at' => 'datetime',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
