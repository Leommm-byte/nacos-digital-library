<?php

namespace App\Notifications;

use App\Models\Voter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A link to choose a new password for a department election account,
 * sent from its space's "Forgot password" page (Vote\PasswordController).
 */
class VoterPasswordReset extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $url) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        assert($notifiable instanceof Voter);
        $space = $notifiable->organization->name.' elections';

        return (new MailMessage)
            ->subject("Reset your password for {$space}")
            ->greeting('Hello '.$notifiable->firstName().',')
            ->line("Someone asked to reset the password of your account for {$space}.")
            ->action('Choose a new password', $this->url)
            ->line('The link works once, for 60 minutes. If you didn\'t ask, ignore this email: your password stays the same.')
            ->salutation('Powered by NACOS YabaTech');
    }
}
