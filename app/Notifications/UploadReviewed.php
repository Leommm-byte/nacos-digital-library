<?php

namespace App\Notifications;

use App\Enums\ReviewAction;
use App\Models\Book;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

/**
 * Tells an uploader what a reviewer decided, with the reviewer's comment.
 * Shown in the app at once; emailed (through the queue) when the
 * uploader has a verified email address.
 */
class UploadReviewed extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Book $book, public ReviewAction $action, public ?string $comment) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $mail = $notifiable instanceof User && $notifiable->email !== null && $notifiable->hasVerifiedEmail();

        return $mail ? ['database', 'mail'] : ['database'];
    }

    /**
     * The in-app notice is written straight away; only email is queued.
     *
     * @return array<string, string>
     */
    public function viaConnections(): array
    {
        return ['database' => 'sync'];
    }

    public function headline(): string
    {
        return match ($this->action) {
            ReviewAction::Approved => "\"{$this->book->title}\" is now in the library",
            ReviewAction::ChangesRequested => "\"{$this->book->title}\" needs a few changes",
            ReviewAction::Rejected => "\"{$this->book->title}\" wasn't approved",
            ReviewAction::Resubmitted => "\"{$this->book->title}\" was resubmitted",
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'book' => $this->book->public_id,
            'title' => $this->book->title,
            'action' => $this->action->value,
            'headline' => $this->headline(),
            'comment' => $this->comment,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->headline())
            ->greeting('Hello!')
            ->line(match ($this->action) {
                ReviewAction::Approved => 'Thanks for sharing. A reviewer approved your upload, and classmates can now find it in the library.',
                ReviewAction::ChangesRequested => 'A reviewer looked at your upload and asked for a few changes before it can go into the library.',
                default => 'A reviewer looked at your upload and decided not to add it to the library.',
            });

        if ($this->comment) {
            $mail->line('Reviewer\'s note: '.$this->comment);
        }

        return $mail->action(
            $this->action === ReviewAction::ChangesRequested ? 'Edit your upload' : 'See your upload',
            route('uploads.show', $this->book),
        );
    }
}
