<?php

namespace App\Support\Departments;

use App\Support\AuditActions;

/**
 * Plain-words names for a department's activity log. Actions not listed
 * are shown with their underscores replaced; colours follow NACOS's audit
 * log (AuditActions::tone).
 */
class ActivityActions
{
    private const LABELS = [
        'voter_registered' => 'Registered',
        'login' => 'Logged in',
        'login_failed' => 'Failed login',
        'logout' => 'Logged out',
        'password_changed' => 'Changed password',
        'password_reset_requested' => 'Asked for a password reset link',
        'password_reset' => 'Reset password with an emailed link',
        'election_voted' => 'Voted',
        'roll_imported' => 'Uploaded a class to the roll',
        'roll_student_added' => 'Added a student to the roll',
        'roll_student_removed' => 'Removed a student from the roll',
        'voter_password_slip' => 'Gave a voter a new password',
        'voter_suspended' => 'Suspended an account',
        'voter_reactivated' => 'Reactivated an account',
        'election_created' => 'Created an election',
        'election_updated' => 'Edited an election',
        'election_deleted' => 'Deleted an election',
        'election_launched' => 'Opened voting',
        'election_closed' => 'Closed voting',
        'election_position_added' => 'Added a position',
        'election_position_removed' => 'Removed a position',
        'election_candidate_added' => 'Added a candidate',
        'election_candidate_removed' => 'Removed a candidate',
        'admin_added' => 'Added an admin',
        'admin_password' => 'Gave an admin a new password',
        'admin_removed' => 'Removed an admin',
        'space_paused' => 'Paused the space',
        'space_resumed' => 'Resumed the space',
        'activity_exported' => 'Exported the activity log',
    ];

    public static function label(string $action): string
    {
        return self::LABELS[$action] ?? ucfirst(str_replace('_', ' ', $action));
    }

    public static function tone(string $action): string
    {
        return match (true) {
            $action === 'voter_registered', str_starts_with($action, 'password_') => 'green',
            str_starts_with($action, 'admin_'), str_starts_with($action, 'space_'), $action === 'voter_password_slip' => 'yellow',
            default => AuditActions::tone($action),
        };
    }

    /**
     * Meta keys worth showing, in plain words.
     *
     * @return array<string, string>
     */
    public static function details(): array
    {
        return [
            'title' => 'Election', 'name' => 'Name', 'matric' => 'Matric', 'login' => 'Tried', 'reason' => 'Why',
            'class' => 'Class', 'file' => 'File', 'mode' => 'Mode', 'total' => 'On the list', 'removed' => 'Removed',
            'moved' => 'Moved class', 'hours' => 'Hours', 'automatic' => 'At its end time', 'ballots' => 'Ballots',
            'position' => 'Position', 'candidate' => 'Candidate', 'email' => 'Email', 'from_slip' => 'After a slip',
            'recorded_later' => 'Recorded later from its record',
        ];
    }
}
