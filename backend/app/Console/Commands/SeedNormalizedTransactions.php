<?php

namespace App\Console\Commands;

use App\Enums\NormalizedTransactionType;
use App\Models\NormalizedTransaction;
use App\Models\TrailerLoad;
use App\Services\NormalizedTransactionService;
use Illuminate\Console\Command;

class SeedNormalizedTransactions extends Command
{
    protected $signature = 'dejavoo:seed-normalized-transactions';

    protected $description = 'Create deterministic local-only normalized transaction samples';

    public function handle(NormalizedTransactionService $service): int
    {
        if (! app()->environment('local')) {
            $this->error('Sample normalized transactions may only be created locally.');

            return self::FAILURE;
        }

        $load = TrailerLoad::query()
            ->with('location:id,unit_price')
            ->orderBy('id')
            ->first();

        if (! $load) {
            $this->error('Create a location and initialize a trailer/load before running this command.');

            return self::FAILURE;
        }

        $unitPrice = (string) $load->location->unit_price;
        $fractionalAmount = bcmul($unitPrice, '0.89', 2);

        if (bccomp($fractionalAmount, '0', 2) <= 0) {
            $fractionalAmount = '0.01';
        }

        $samples = [
            [NormalizedTransactionType::Sale, $unitPrice, 'sale'],
            [NormalizedTransactionType::Refund, $fractionalAmount, 'refund-fractional'],
            [NormalizedTransactionType::Void, '1.00', 'void'],
        ];

        $created = 0;

        foreach ($samples as [$type, $amount, $suffix]) {
            $externalId = "dev-sample-load-{$load->id}-{$suffix}-v1";

            if (NormalizedTransaction::query()
                ->where('source', 'development_sample')
                ->where('external_transaction_id', $externalId)
                ->exists()) {
                continue;
            }

            $service->create(
                locationId: $load->location_id,
                transactionType: $type,
                businessAmount: $amount,
                occurredAt: $load->started_at,
                source: 'development_sample',
                externalTransactionId: $externalId,
            );
            $created++;
        }

        $this->info("Created {$created} normalized sample transaction(s) for load {$load->id}.");

        return self::SUCCESS;
    }
}
