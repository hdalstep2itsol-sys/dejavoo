<?php

namespace App\Notifications;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WarehouseLoadPending extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $loadId,
        public readonly string $locationName,
        public readonly string $swappedAt,
        public readonly string $driverName = 'Not recorded',
        public readonly string $operationalUnits = '0.00000000',
    ) {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return $notifiable instanceof AnonymousNotifiable
            || ($notifiable instanceof User && $notifiable->is_active
            && in_array($notifiable->role, [UserRole::OwnerAdmin, UserRole::WarehouseStaff], true));
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Warehouse count pending: {$this->locationName}")
            ->greeting('A trailer is awaiting warehouse count')
            ->line("The swapped trailer from {$this->locationName} is ready for warehouse processing.")
            ->line("Swapped at: {$this->swappedAt}")
            ->line("Swapped by: {$this->driverName}")
            ->line("Estimated operational units: {$this->operationalUnits}")
            ->line("Load ID: {$this->loadId}")
            ->line('Open the Logistics Hub warehouse workflow to enter the actual count.');
    }
}
