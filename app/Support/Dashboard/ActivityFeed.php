<?php

namespace App\Support\Dashboard;

use App\Models\AuditLog;
use App\Models\Book;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * A user's own recent actions in plain words, from the audit log. Only
 * actions listed here are shown; everything else stays in the log.
 */
class ActivityFeed
{
    /**
     * action => [icon, sentence]; ":book" is replaced with the book's title.
     */
    private const ACTIONS = [
        'login' => ['log-in', 'You logged in'],
        'login_failed' => ['triangle-alert', 'Someone tried to log in to your account with a wrong password'],
        'two_factor_failed' => ['triangle-alert', 'A wrong two-step code was entered for your account'],
        'user_registered' => ['user-plus', 'You joined NACOS YabaTech'],
        'profile_updated' => ['user', 'You updated your profile'],
        'password_changed' => ['key-round', 'You changed your password'],
        'password_reset' => ['key-round', 'Your password was reset'],
        'email_changed' => ['mail', 'You changed your email address'],
        'email_verified' => ['circle-check', 'You confirmed your email address'],
        'two_factor_enabled' => ['shield-check', 'You turned on two-step verification'],
        'two_factor_disabled' => ['shield-check', 'You turned off two-step verification'],
        'book_uploaded' => ['upload', 'You shared ":book"'],
        'book_edited' => ['file-up', 'You edited ":book"'],
        'book_resubmitted' => ['upload', 'You sent ":book" back for review'],
        'book_deleted' => ['x', 'You deleted ":book"'],
        'book_approved' => ['circle-check', 'You approved ":book"'],
        'book_changes_requested' => ['triangle-alert', 'You asked for changes to ":book"'],
        'book_rejected' => ['x', 'You rejected ":book"'],
    ];

    /**
     * @return list<array{icon: string, text: string, at: Carbon}>
     */
    public static function forUser(User $user, int $limit = 5): array
    {
        $entries = AuditLog::query()
            ->where('user_id', $user->id)
            ->whereIn('action', array_keys(self::ACTIONS))
            ->latest('id')
            ->limit($limit * 4)
            ->get(['id', 'action', 'subject_type', 'subject_id', 'meta', 'created_at']);

        // Every visit logs in, so only the latest login is shown.
        $seenLogin = false;
        $entries = $entries->filter(function (AuditLog $entry) use (&$seenLogin) {
            if ($entry->action !== 'login') {
                return true;
            }
            $keep = ! $seenLogin;
            $seenLogin = true;

            return $keep;
        })->take($limit);

        $bookIds = $entries->where('subject_type', 'Book')->pluck('subject_id')->filter()->unique()->all();
        $titles = $bookIds === [] ? [] : Book::withTrashed()->whereIn('id', $bookIds)->pluck('title', 'id')->all();

        return $entries->map(function (AuditLog $entry) use ($titles) {
            [$icon, $text] = self::ACTIONS[$entry->action];
            $title = $titles[$entry->subject_id] ?? ($entry->meta['title'] ?? 'a book');

            return [
                'icon' => $icon,
                'text' => str_replace(':book', (string) $title, $text),
                'at' => $entry->created_at,
            ];
        })->values()->all();
    }
}
