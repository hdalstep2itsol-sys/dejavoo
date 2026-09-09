<?php

namespace App\Models;

use App\Enums\DriverCommitmentSource;
use App\Enums\TrailerLoadStatus;
use App\Services\NormalizedTransactionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrailerLoad extends Model
{
    use HasFactory;

    protected $fillable = [
        'location_id',
        'status',
        'started_at',
        'created_by_user_id',
        'committed_driver_id',
        'commitment_source',
        'committed_at',
        'committed_by_user_id',
        'swapped_at',
        'swapped_by_user_id',
        'warehouse_actual_count',
        'warehouse_notes',
        'warehouse_confirmed_at',
        'warehouse_confirmed_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => TrailerLoadStatus::class,
            'started_at' => 'datetime',
            'commitment_source' => DriverCommitmentSource::class,
            'committed_at' => 'datetime',
            'swapped_at' => 'datetime',
            'warehouse_actual_count' => 'integer',
            'warehouse_confirmed_at' => 'datetime',
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

    public function committedDriver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'committed_driver_id');
    }

    public function committedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'committed_by_user_id');
    }

    public function swappedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'swapped_by_user_id');
    }

    public function warehouseConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'warehouse_confirmed_by_user_id');
    }

    public function normalizedTransactions(): HasMany
    {
        return $this->hasMany(NormalizedTransaction::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(TrailerLoadAdjustment::class);
    }

    public function scopeWithUnitTotals(Builder $query): Builder
    {
        return $query
            ->withSum('normalizedTransactions', 'unit_delta')
            ->withSum('adjustments', 'unit_delta');
    }

    public function calculatedUnits(): string
    {
        $sum = array_key_exists('normalized_transactions_sum_unit_delta', $this->attributes)
            ? $this->normalized_transactions_sum_unit_delta
            : $this->normalizedTransactions()->sum('unit_delta');

        return bcadd(
            (string) ($sum ?? '0'),
            '0',
            NormalizedTransactionService::UNIT_SCALE,
        );
    }

    public function manualAdjustmentUnits(): string
    {
        $sum = array_key_exists('adjustments_sum_unit_delta', $this->attributes)
            ? $this->adjustments_sum_unit_delta
            : $this->adjustments()->sum('unit_delta');

        return bcadd(
            (string) ($sum ?? '0'),
            '0',
            NormalizedTransactionService::UNIT_SCALE,
        );
    }

    public function operationalUnits(): string
    {
        return bcadd(
            $this->calculatedUnits(),
            $this->manualAdjustmentUnits(),
            NormalizedTransactionService::UNIT_SCALE,
        );
    }

    public function operationalStatus(): string
    {
        return bccomp(
            $this->operationalUnits(),
            (string) $this->location->haul_threshold,
            NormalizedTransactionService::UNIT_SCALE,
        ) >= 0 ? 'ready' : 'active';
    }

    public function progressPercentage(): string
    {
        return bcdiv(
            bcmul($this->operationalUnits(), '100', 10),
            (string) $this->location->haul_threshold,
            2,
        );
    }

    public function warehouseVariance(): ?string
    {
        if ($this->warehouse_actual_count === null) {
            return null;
        }

        return bcsub(
            (string) $this->warehouse_actual_count,
            $this->operationalUnits(),
            NormalizedTransactionService::UNIT_SCALE,
        );
    }

    public function loadUnitTotals(): static
    {
        return $this
            ->loadSum('normalizedTransactions', 'unit_delta')
            ->loadSum('adjustments', 'unit_delta');
    }
}
