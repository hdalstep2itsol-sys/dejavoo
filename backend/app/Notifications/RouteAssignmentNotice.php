<?php

namespace App\Notifications;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RouteAssignmentNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $locationName,
        public readonly ?int $loadId,
        public readonly string $subject,
        public readonly string $details,
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
        $message = (new MailMessage)
            ->subject("{$this->subject}: {$this->locationName}")
            ->greeting($this->subject)
            ->line($this->details)
            ->line("Location: {$this->locationName}");

        return $this->loadId === null ? $message : $message->line("Load ID: {$this->loadId}");
    }
}
