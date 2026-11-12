<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class IpospaysFeedFailureAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $failureCount,
        public readonly int $windowSeconds,
        public readonly string $lastStage,
        public readonly string $detectedAt,
    ) {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('iPOSpays FEED processing warning')
            ->greeting('Repeated FEED processing failures detected')
            ->line("Failures in the current alert window: {$this->failureCount}")
            ->line("Alert window: {$this->windowSeconds} seconds")
            ->line("Last processing stage: {$this->lastStage}")
            ->line("Detected at: {$this->detectedAt}")
            ->line('Review the sanitized application logs for the correlated failure details.');
    }
}
