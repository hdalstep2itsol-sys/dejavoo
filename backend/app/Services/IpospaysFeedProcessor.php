<?php

namespace App\Services;

use App\Data\IpospaysFeedProcessingResult;
use App\Data\MappedIpospaysFeedTransaction;
use App\Enums\IpospaysFeedEventStatus;
use App\Exceptions\IpospaysTerminalResolutionException;
use App\Exceptions\NormalizedTransactionException;
use App\Models\IpospaysFeedEvent;
use App\Models\NormalizedTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class IpospaysFeedProcessor
{
    public const SOURCE = 'ipospays_feed';

    public function __construct(
        private readonly IpospaysTerminalResolver $terminals,
        private readonly NormalizedTransactionService $transactions,
    ) {}

    public function recordPendingProviderMapping(): IpospaysFeedEvent
    {
        $now = now();
        $event = IpospaysFeedEvent::query()->create([
            'status' => IpospaysFeedEventStatus::PendingProviderMapping,
            'error_code' => 'provider_mapping_unfinalized',
            'delivery_count' => 1,
            'last_received_at' => $now,
            'authenticated_at' => $now,
        ]);
        $this->log('provider_mapping_unfinalized', $event);

        return $event;
    }

    public function process(MappedIpospaysFeedTransaction $data): IpospaysFeedProcessingResult
    {
        return DB::transaction(function () use ($data): IpospaysFeedProcessingResult {
            [$event, $created] = $this->receiveEvent($data);

            if (! $created) {
                if (! hash_equals($event->payload_fingerprint, $data->payloadFingerprint())) {
                    return $this->conflict($event, 'event_payload_conflict');
                }

                if ($event->normalized_transaction_id !== null
                    || in_array($event->status, [
                        IpospaysFeedEventStatus::Processed,
                        IpospaysFeedEventStatus::Duplicate,
                    ], true)) {
                    $this->log('duplicate_event', $event);

                    return new IpospaysFeedProcessingResult(
                        IpospaysFeedEventStatus::Duplicate,
                        $event,
                        $event->normalizedTransaction,
                    );
                }

                if (in_array($event->status, [
                    IpospaysFeedEventStatus::Conflict,
                    IpospaysFeedEventStatus::Unsupported,
                ], true)) {
                    return new IpospaysFeedProcessingResult($event->status, $event);
                }

                $this->log('event_retry', $event);
            }

            try {
                $terminal = $this->terminals->resolve($data->tpn, $data->termId);
            } catch (IpospaysTerminalResolutionException $exception) {
                $event->update([
                    'status' => $exception->status,
                    'error_code' => $exception->errorCode,
                ]);
                $this->log($exception->errorCode, $event);

                return new IpospaysFeedProcessingResult($exception->status, $event->refresh());
            }

            $event->update([
                'dejavoo_terminal_id' => $terminal->id,
                'location_id' => $terminal->location_id,
            ]);

            if ($this->hasFinancialConflict($event, $data)) {
                return $this->conflict($event, 'transaction_payload_conflict');
            }

            $existing = $this->existingTransaction($data);
            if ($existing) {
                return $this->completeExisting($event, $existing, $terminal->id, $data);
            }

            try {
                $transaction = $this->transactions->create(
                    locationId: $terminal->location_id,
                    transactionType: $data->transactionType,
                    businessAmount: $data->businessAmount,
                    occurredAt: $data->occurredAt,
                    source: self::SOURCE,
                    externalTransactionId: $data->providerTransactionId,
                    dejavooTerminalId: $terminal->id,
                );
            } catch (QueryException $exception) {
                $transaction = $this->existingTransaction($data);
                if (! $transaction) {
                    throw $exception;
                }

                if ($this->hasFinancialConflict($event, $data)) {
                    return $this->conflict($event, 'transaction_payload_conflict');
                }

                return $this->completeExisting($event, $transaction, $terminal->id, $data);
            } catch (NormalizedTransactionException) {
                $event->update([
                    'status' => IpospaysFeedEventStatus::Failed,
                    'error_code' => 'normalization_rejected',
                ]);
                $this->log('normalization_rejected', $event);

                return new IpospaysFeedProcessingResult(
                    IpospaysFeedEventStatus::Failed,
                    $event->refresh(),
                );
            }

            $event->update([
                'status' => IpospaysFeedEventStatus::Processed,
                'error_code' => null,
                'normalized_transaction_id' => $transaction->id,
                'processed_at' => now(),
            ]);
            $this->log('processed', $event);

            return new IpospaysFeedProcessingResult(
                IpospaysFeedEventStatus::Processed,
                $event->refresh(),
                $transaction,
            );
        }, 3);
    }

    /**
     * @return array{IpospaysFeedEvent, bool}
     */
    private function receiveEvent(MappedIpospaysFeedTransaction $data): array
    {
        $now = now();
        $inserted = DB::table('ipospays_feed_events')->insertOrIgnore([
            ...$data->receiptAttributes(),
            'delivery_count' => 1,
            'last_received_at' => $now,
            'authenticated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $event = IpospaysFeedEvent::query()
            ->where('provider_event_id', $data->providerEventId)
            ->lockForUpdate()
            ->firstOrFail();

        if ($inserted === 0) {
            $event->increment('delivery_count');
            $event->update(['last_received_at' => $now]);
            $event->refresh();
        }

        return [$event, $inserted === 1];
    }

    private function existingTransaction(MappedIpospaysFeedTransaction $data): ?NormalizedTransaction
    {
        return NormalizedTransaction::query()
            ->where('source', self::SOURCE)
            ->where('external_transaction_id', $data->providerTransactionId)
            ->first();
    }

    private function completeExisting(
        IpospaysFeedEvent $event,
        NormalizedTransaction $transaction,
        int $terminalId,
        MappedIpospaysFeedTransaction $data,
    ): IpospaysFeedProcessingResult {
        if (! $this->sameImmutableTransaction($transaction, $terminalId, $data)) {
            return $this->conflict($event, 'normalized_transaction_conflict');
        }

        $event->update([
            'status' => IpospaysFeedEventStatus::Duplicate,
            'error_code' => null,
            'normalized_transaction_id' => $transaction->id,
            'processed_at' => now(),
        ]);
        $this->log('duplicate_transaction', $event);

        return new IpospaysFeedProcessingResult(
            IpospaysFeedEventStatus::Duplicate,
            $event->refresh(),
            $transaction,
        );
    }

    private function sameImmutableTransaction(
        NormalizedTransaction $transaction,
        int $terminalId,
        MappedIpospaysFeedTransaction $data,
    ): bool {
        return $transaction->dejavoo_terminal_id === $terminalId
            && $transaction->transaction_type === $data->transactionType
            && bccomp($transaction->business_amount, $data->businessAmount, 2) === 0
            && $transaction->occurred_at->equalTo($data->occurredAt);
    }

    private function hasFinancialConflict(
        IpospaysFeedEvent $event,
        MappedIpospaysFeedTransaction $data,
    ): bool {
        return IpospaysFeedEvent::query()
            ->where('provider_transaction_id', $data->providerTransactionId)
            ->whereKeyNot($event->id)
            ->whereNotNull('financial_fingerprint')
            ->where('financial_fingerprint', '!=', $data->financialFingerprint())
            ->exists();
    }

    private function conflict(
        IpospaysFeedEvent $event,
        string $errorCode,
    ): IpospaysFeedProcessingResult {
        $event->update([
            'status' => IpospaysFeedEventStatus::Conflict,
            'error_code' => $errorCode,
        ]);
        $this->log($errorCode, $event);

        return new IpospaysFeedProcessingResult(
            IpospaysFeedEventStatus::Conflict,
            $event->refresh(),
        );
    }

    private function log(string $code, IpospaysFeedEvent $event): void
    {
        Log::info('iPOSpays FEED event state changed.', [
            'code' => $code,
            'feed_event_id' => $event->id,
            'terminal_id' => $event->dejavoo_terminal_id,
            'location_id' => $event->location_id,
            'normalized_transaction_id' => $event->normalized_transaction_id,
            'status' => $event->status->value,
        ]);
    }
}
