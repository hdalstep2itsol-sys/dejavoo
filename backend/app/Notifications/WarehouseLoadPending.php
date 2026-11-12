<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WarehouseLoadPending extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $loadId,
        public readonly string $locationName,
        public readonly string $swappedAt,
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
            ->subject("Warehouse count pending: {$this->locationName}")
            ->greeting('A trailer is awaiting warehouse count')
            ->line("The swapped trailer from {$this->locationName} is ready for warehouse processing.")
            ->line("Swapped at: {$this->swappedAt}")
            ->line("Load ID: {$this->loadId}")
            ->line('Open the Logistics Hub warehouse workflow to enter the actual count.');
    }
}
