<?php

namespace App\Support;

/**
 * Plain-words names for audit log actions, for the admin pages. Actions
 * not listed are shown with their underscores replaced.
 */
class AuditActions
{
    private const LABELS = [
        'login' => 'Logged in',
        'login_failed' => 'Failed login',
        'logout' => 'Logged out',
        'two_factor_failed' => 'Wrong two-step code',
        'user_registered' => 'Signed up',
        'profile_updated' => 'Updated profile',
        'password_changed' => 'Changed password',
        'password_reset' => 'Reset password',
        'email_changed' => 'Changed email',
        'email_verified' => 'Confirmed email',
        'two_factor_enabled' => 'Turned on two-step',
        'two_factor_disabled' => 'Turned off two-step',
        'book_uploaded' => 'Uploaded a book',
        'book_edited' => 'Edited an upload',
        'book_resubmitted' => 'Resubmitted an upload',
        'book_deleted' => 'Deleted a book',
        'book_approved' => 'Approved a book',
        'book_changes_requested' => 'Asked for changes',
        'book_rejected' => 'Rejected a book',
        'announcement_created' => 'Posted an announcement',
        'announcement_updated' => 'Edited an announcement',
        'announcement_deleted' => 'Deleted an announcement',
        'election_created' => 'Created an election',
        'election_updated' => 'Edited an election',
        'election_deleted' => 'Deleted an election',
        'election_launched' => 'Opened voting',
        'election_closed' => 'Closed voting',
        'election_voted' => 'Voted',
        'roll_imported' => 'Uploaded a class to the roll',
        'accounts_created' => 'Created accounts',
        'user_role_changed' => 'Changed a role',
        'user_suspended' => 'Suspended an account',
        'user_reactivated' => 'Reactivated an account',
        'user_class_changed' => 'Changed a class',
        'user_two_factor_reset' => 'Turned off someone\'s two-step',
        'user_password_reset' => 'Gave a new password',
        'settings_updated' => 'Changed settings',
        'department_opened' => 'Opened a department',
        'department_closed' => 'Closed a department',
    ];

    public static function label(string $action): string
    {
        return self::LABELS[$action] ?? ucfirst(str_replace('_', ' ', $action));
    }

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        return self::LABELS;
    }
}
