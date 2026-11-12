<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TrailerLoadThresholdReached extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $loadId,
        public readonly string $locationName,
        public readonly string $operationalUnits,
        public readonly string $haulThreshold,
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
            ->subject("Trailer threshold reached: {$this->locationName}")
            ->greeting('Trailer threshold reached')
            ->line("{$this->locationName} has reached or exceeded its haul threshold.")
            ->line("Operational units: {$this->operationalUnits}")
            ->line("Haul threshold: {$this->haulThreshold}")
            ->line("Load ID: {$this->loadId}")
            ->line('Open the Logistics Hub to review the active route.');
    }
}
