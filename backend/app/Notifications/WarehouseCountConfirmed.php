<?php

namespace App\Notifications;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WarehouseCountConfirmed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $loadId,
        public readonly string $locationName,
        public readonly string $estimatedUnits,
        public readonly int $actualUnits,
        public readonly string $variance,
        public readonly string $confirmedAt,
    ) {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return $notifiable instanceof User && $notifiable->is_active
            && $notifiable->role === UserRole::OwnerAdmin;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Warehouse count confirmed: {$this->locationName}")
            ->greeting('Warehouse count confirmed')
            ->line("Location: {$this->locationName}")
            ->line("Load ID: {$this->loadId}")
            ->line("Estimated operational units: {$this->estimatedUnits}")
            ->line("Warehouse actual count: {$this->actualUnits}")
            ->line("Variance: {$this->variance}")
            ->line("Confirmed at: {$this->confirmedAt}");
    }
}
