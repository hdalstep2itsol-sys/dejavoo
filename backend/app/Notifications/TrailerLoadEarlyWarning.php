<?php

namespace App\Notifications;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TrailerLoadEarlyWarning extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $loadId,
        public readonly string $locationName,
        public readonly string $operationalUnits,
        public readonly string $warningThreshold,
        public readonly string $haulThreshold,
        public readonly string $driverName,
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
            && in_array($notifiable->role, [UserRole::OwnerAdmin, UserRole::Driver], true);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Trailer approaching threshold: {$this->locationName}")
            ->greeting('Trailer early warning')
            ->line("{$this->locationName} has reached its configured early warning threshold.")
            ->line("Operational units: {$this->operationalUnits}")
            ->line("Early warning: {$this->warningThreshold}")
            ->line("Haul threshold: {$this->haulThreshold}")
            ->line("Driver: {$this->driverName}")
            ->line("Load ID: {$this->loadId}");
    }
}
