<?php

namespace App\Support\Sessions;

use App\Enums\Programme;
use App\Support\Settings;

/**
 * Each programme keeps its own academic calendar: full-time, part-time
 * and CODFEL start their sessions and write their exams at different
 * times. This holds each programme's current session and whether its exam
 * timetable is shown to students.
 */
class ProgrammeSessions
{
    public static function session(Programme $programme): ?string
    {
        return Settings::get('academic_session_'.$programme->value);
    }

    public static function examsPublished(Programme $programme): bool
    {
        return Settings::get('exams_published_'.$programme->value) === '1';
    }

    public static function setSession(Programme $programme, ?string $session): void
    {
        Settings::put(['academic_session_'.$programme->value => $session]);
    }

    public static function setExamsPublished(Programme $programme, bool $published): void
    {
        Settings::put(['exams_published_'.$programme->value => $published ? 1 : 0]);
    }

    /**
     * "Full-time 2026/2027 · Part-time 2025/2026", for the admin dashboard.
     */
    public static function summary(): ?string
    {
        $parts = [];
        foreach (Programme::cases() as $programme) {
            if (($session = self::session($programme)) !== null) {
                $parts[] = $programme->label().' '.$session;
            }
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }
}
