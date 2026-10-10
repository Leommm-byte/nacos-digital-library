<?php

namespace App\Support;

use App\Enums\Programme;

/**
 * YabaTech matric numbers: F/ND/24/1234567 is the programme letter (F
 * full-time, P part-time, D CODFEL), ND or HND/HD, the two-digit entry
 * year and seven digits.
 *
 * The older ND/2019/CS/1234 format (students who have left by now) is no
 * longer accepted, but its entry year and level are still read, for any
 * account that has one.
 */
class MatricNumber
{
    public const PATTERN = '#^[FPD]/(?:ND|HND|HD)/[0-9]{2}/[0-9]{7}$#';

    /** Entry years older than this many years are not current students. */
    public const MAX_YEARS_AGO = 10;

    public static function normalize(?string $value): string
    {
        return strtoupper(trim((string) $value));
    }

    public static function isValid(?string $value): bool
    {
        return self::problem($value) === null;
    }

    /**
     * What is wrong with a matric number, in plain words, or null when it
     * is fine: the format, seven final digits, and an entry year that has
     * started and isn't from long ago.
     */
    public static function problem(?string $value): ?string
    {
        $value = self::normalize($value);

        if (preg_match(self::PATTERN, $value) !== 1) {
            return preg_match('#^[FPD]/(?:ND|HND|HD)/[0-9]{2}/[0-9]+$#', $value) === 1
                ? 'The last part of a matric number has 7 digits, for example F/ND/24/1234567.'
                : 'Enter the matric number as printed on the ID card, for example F/ND/24/1234567.';
        }

        $year = (int) self::entryYear($value);
        $now = now()->timezone((string) config('app.display_timezone', 'Africa/Lagos'))->year;

        if ($year > $now) {
            return "This matric number's year ({$year}) hasn't started yet. Check it against the ID card.";
        }

        if ($year < $now - self::MAX_YEARS_AGO) {
            return "This matric number is from {$year}, too long ago for a current student.";
        }

        return null;
    }

    /**
     * The four-digit entry year in a matric number: F/ND/24/… is 2024 and
     * ND/2019/CS/… is 2019. Null when the number has neither format.
     */
    public static function entryYear(?string $value): ?int
    {
        $value = self::normalize($value);

        if (preg_match('#^[FPD]/(?:ND|HND|HD)/([0-9]{2})/#', $value, $match)) {
            return 2000 + (int) $match[1];
        }

        if (preg_match('#^(?:ND|HND)/([0-9]{4})/#', $value, $match)) {
            return (int) $match[1];
        }

        return null;
    }

    /**
     * The programme in a matric number's first letter, or null for the
     * older format, which doesn't say.
     */
    public static function programme(?string $value): ?Programme
    {
        return match (substr(self::normalize($value), 0, 2)) {
            'F/' => Programme::FullTime,
            'P/' => Programme::PartTime,
            'D/' => Programme::Codfel,
            default => null,
        };
    }

    /**
     * Whether the number is for HND (true) or ND (false); null when it
     * isn't a matric number.
     */
    public static function isHnd(?string $value): ?bool
    {
        if (! preg_match('#^(?:[FPD]/(ND|HND|HD)/|(ND|HND)/[0-9]{4}/)#', self::normalize($value), $match)) {
            return null;
        }

        return $match[1] !== '' ? $match[1] !== 'ND' : $match[2] === 'HND';
    }
}
