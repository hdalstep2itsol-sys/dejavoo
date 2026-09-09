<?php

namespace App\Services;

use App\Enums\NormalizedTransactionType;
use App\Exceptions\NormalizedTransactionException;
use App\Models\DejavooTerminal;
use App\Models\Location;
use App\Models\NormalizedTransaction;
use App\Models\TrailerLoad;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class NormalizedTransactionService
{
    public const UNIT_SCALE = 8;

    public function __construct(private readonly LocationPriceService $priceService) {}

    public function create(
        int $locationId,
        NormalizedTransactionType $transactionType,
        string $businessAmount,
        CarbonInterface $occurredAt,
        string $source,
        ?string $externalTransactionId = null,
        ?int $dejavooTerminalId = null,
    ): NormalizedTransaction {
        $this->validateAmount($businessAmount);
        $this->validateSource($source);
        $this->validateExternalTransactionId($externalTransactionId);

        return DB::transaction(function () use (
            $locationId,
            $transactionType,
            $businessAmount,
            $occurredAt,
            $source,
            $externalTransactionId,
            $dejavooTerminalId,
        ) {
            $location = Location::query()->findOrFail($locationId);
            $load = $this->resolveLoad($location, $occurredAt);
            $this->validateTerminal($location, $dejavooTerminalId);

            $unitPrice = (string) $this->priceService
                ->resolveAt($location, $occurredAt)
                ->unit_price;

            if (bccomp($unitPrice, '0', 2) <= 0) {
                throw new NormalizedTransactionException(
                    'The location must have a positive unit price.',
                );
            }

            $unitMagnitude = bcdiv($businessAmount, $unitPrice, self::UNIT_SCALE);
            $unitDelta = $transactionType->sign() < 0
                ? '-'.$unitMagnitude
                : $unitMagnitude;

            return NormalizedTransaction::query()->create([
                'location_id' => $location->getKey(),
                'trailer_load_id' => $load->getKey(),
                'dejavoo_terminal_id' => $dejavooTerminalId,
                'source' => trim($source),
                'external_transaction_id' => $externalTransactionId === null
                    ? null
                    : trim($externalTransactionId),
                'transaction_type' => $transactionType,
                'business_amount' => $businessAmount,
                'unit_price_snapshot' => $unitPrice,
                'unit_delta' => $unitDelta,
                'occurred_at' => $occurredAt,
            ]);
        }, 3);
    }

    private function resolveLoad(Location $location, CarbonInterface $occurredAt): TrailerLoad
    {
        $load = $location->trailerLoads()
            ->where('started_at', '<=', $occurredAt)
            ->where(function ($query) use ($occurredAt) {
                $query->whereNull('swapped_at')
                    ->orWhere('swapped_at', '>', $occurredAt);
            })
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first();

        if (! $load) {
            throw new NormalizedTransactionException(
                'No trailer/load cycle covers the transaction timestamp.',
            );
        }

        return $load;
    }

    private function validateAmount(string $businessAmount): void
    {
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $businessAmount)
            || bccomp($businessAmount, '0', 2) <= 0) {
            throw new NormalizedTransactionException(
                'Business amount must be a positive decimal with at most two decimal places.',
            );
        }
    }

    private function validateSource(string $source): void
    {
        $length = mb_strlen(trim($source));

        if ($length === 0 || $length > 64) {
            throw new NormalizedTransactionException(
                'Transaction source must contain between 1 and 64 characters.',
            );
        }
    }

    private function validateExternalTransactionId(?string $externalTransactionId): void
    {
        if ($externalTransactionId === null) {
            return;
        }

        $length = mb_strlen(trim($externalTransactionId));

        if ($length === 0 || $length > 191) {
            throw new NormalizedTransactionException(
                'External transaction ID must contain between 1 and 191 characters.',
            );
        }
    }

    private function validateTerminal(Location $location, ?int $terminalId): void
    {
        if ($terminalId === null) {
            return;
        }

        $belongsToLocation = DejavooTerminal::query()
            ->whereKey($terminalId)
            ->where('location_id', $location->getKey())
            ->exists();

        if (! $belongsToLocation) {
            throw new NormalizedTransactionException(
                'The terminal mapping does not belong to the selected location.',
            );
        }
    }
}
