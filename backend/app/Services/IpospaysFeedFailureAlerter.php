<?php

namespace App\Services;

use App\Notifications\IpospaysFeedFailureAlert;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

class IpospaysFeedFailureAlerter
{
    private const RECENT_FAILURE_KEY = 'ipospays-feed:failures:recent';

    public function record(string $processingStage): void
    {
        try {
            $recentSeconds = max(60, (int) config('operations.feed_health.recent_failure_seconds', 86400));
            $this->incrementExpiring(self::RECENT_FAILURE_KEY, $recentSeconds);

            $windowSeconds = max(60, (int) config('operations.feed_alerts.window_seconds', 900));
            $threshold = max(1, (int) config('operations.feed_alerts.threshold', 3));
            $bucket = intdiv(now()->timestamp, $windowSeconds);
            $windowKey = "ipospays-feed:failures:window:{$bucket}";
            $failureCount = $this->incrementExpiring($windowKey, $windowSeconds * 2);
            $recipient = trim((string) config('operations.feed_alerts.recipient'));

            if ($recipient === '' || $failureCount < $threshold) {
                return;
            }

            DB::afterCommit(function () use ($windowKey, $windowSeconds, $recipient, $failureCount, $processingStage): void {
                $alertedKey = "{$windowKey}:alerted";

                try {
                    if (! Cache::add($alertedKey, true, $windowSeconds * 2)) {
                        return;
                    }

                    try {
                        Notification::route('mail', $recipient)->notify(
                            new IpospaysFeedFailureAlert(
                                $failureCount,
                                $windowSeconds,
                                mb_substr($processingStage, 0, 64),
                                now()->toIso8601String(),
                            ),
                        );
                    } catch (Throwable $exception) {
                        Cache::forget($alertedKey);

                        throw $exception;
                    }
                } catch (Throwable $exception) {
                    $this->logDispatchFailure($exception);
                }
            });
        } catch (Throwable $exception) {
            $this->logDispatchFailure($exception);
        }
    }

    public function recentFailureCount(): int
    {
        return max(0, (int) Cache::get(self::RECENT_FAILURE_KEY, 0));
    }

    private function incrementExpiring(string $key, int $seconds): int
    {
        Cache::add($key, 0, $seconds);

        return (int) Cache::increment($key);
    }

    private function logDispatchFailure(Throwable $exception): void
    {
        Log::warning('iPOSpays FEED failure alert could not be queued.', [
            'code' => 'feed_failure_alert_dispatch_failed',
            'exception_class' => $exception::class,
        ]);
    }
}
