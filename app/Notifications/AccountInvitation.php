<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The invitation email: a one-time link to choose a password. Sent by
 * App\Support\Accounts\Invitations, a few at a time.
 */
class AccountInvitation extends Notification
{
    public function __construct(public string $token, public bool $fromSignup = false) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        assert($notifiable instanceof User);
        $app = (string) config('app.name');
        $days = (int) config('mail.invitations.expire_days');

        $mail = (new MailMessage)
            ->subject($this->fromSignup ? "Finish signing up to {$app}" : "Your {$app} account is ready")
            ->greeting('Hello '.$notifiable->greetingName().',');

        $mail = $this->fromSignup
            ? $mail->line("Someone started signing up with your matric number, {$notifiable->matric_number}. Your class list has this email address, so we sent the link here to make sure it's you.")
            : $mail->line("NACOS has made an account for you with your matric number, {$notifiable->matric_number}. Choose a password to start using the library, timetables and elections.");

        return $mail
            ->action('Choose your password', route('invitation.show', $this->token))
            ->line("The link works once and for {$days} days. You'll log in with your matric number and the password you choose.")
            ->line($this->fromSignup
                ? "If it wasn't you, ignore this email: nobody can use the account without this link. Tell a NACOS admin if it keeps happening."
                : 'If this isn\'t you, ignore this email and tell a NACOS admin.');
    }
}
