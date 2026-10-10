<?php

namespace App\Support\Timetables;

/**
 * Times of day as timetables write them: "8:00", "08.30", "2pm", "2:30 PM",
 * "14:00", or an Excel time (a fraction of a day, 0.375 is 9:00). Stored as
 * "HH:MM". Lectures run from morning to evening, so an hour from 1 to 6
 * without am or pm is afternoon ("2:00" is 14:00).
 */
class TimeOfDay
{
    public static function parse(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));

        if ($value === '') {
            return null;
        }

        // Excel keeps times as a fraction of a day.
        if (is_numeric($value) && (float) $value > 0 && (float) $value < 1) {
            $minutes = (int) round((float) $value * 1440);

            return sprintf('%02d:%02d', intdiv($minutes, 60) % 24, $minutes % 60);
        }

        if (! preg_match('/^(\d{1,2})(?:[:.](\d{2}))?(?::\d{2})?\s*(am|pm|a\.m\.|p\.m\.)?$/', $value, $match)) {
            return null;
        }

        $hour = (int) $match[1];
        $minute = isset($match[2]) && $match[2] !== '' ? (int) $match[2] : 0;
        $meridiem = isset($match[3]) ? $match[3][0] : null;

        if ($minute > 59 || $hour > 23 || ($meridiem !== null && ($hour < 1 || $hour > 12))) {
            return null;
        }

        if ($meridiem === 'p' && $hour < 12) {
            $hour += 12;
        } elseif ($meridiem === 'a' && $hour === 12) {
            $hour = 0;
        } elseif ($meridiem === null && $hour >= 1 && $hour <= 6) {
            $hour += 12;
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    /**
     * "8:00 – 10:00" or "8-10am" in one cell.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function range(?string $value): ?array
    {
        $parts = preg_split('/\s*(?:-|–|—|to)\s*/u', strtolower(trim((string) $value)));

        if ($parts === false || count($parts) !== 2) {
            return null;
        }

        // "8-10am": the end's am or pm goes for the start too.
        if (preg_match('/(am|pm)$/', $parts[1], $meridiem) && ! preg_match('/[ap]\.?m\.?$/', $parts[0])) {
            $start = self::parse($parts[0].$meridiem[1]);
            if ($start !== null && $start > (string) self::parse($parts[1])) {
                $start = self::parse($parts[0]);
            }
        } else {
            $start = self::parse($parts[0]);
        }

        $end = self::parse($parts[1]);

        return $start !== null && $end !== null ? [$start, $end] : null;
    }

    /** "14:30:00" → "2:30 pm" */
    public static function format(?string $time): string
    {
        if ($time === null || ! preg_match('/^(\d{2}):(\d{2})/', $time, $match)) {
            return '';
        }

        $hour = (int) $match[1];

        return sprintf('%d:%s %s', $hour % 12 === 0 ? 12 : $hour % 12, $match[2], $hour < 12 ? 'am' : 'pm');
    }

    /** "14:30:00" → minutes since midnight. */
    public static function minutes(string $time): int
    {
        return (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
    }
}
