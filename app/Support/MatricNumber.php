<?php

namespace App\Support;

use App\Enums\Programme;

/**
 * YabaTech matric number formats, as accepted by the original app:
 *   F/ND/24/1234567    programme letter (F full-time, P part-time, D CODFEL),
 *                      ND/HND/HD, two-digit entry year (19 onwards), number
 *   ND/2019/CS/1234    older format: level, year, department code, number
 */
class MatricNumber
{
    public const PATTERN = '#^(?:[FPD]/(?:ND|HND|HD)/(?:19|[2-9][0-9])/[0-9]{3,10}|(?:ND|HND)/[0-9]{4}/[A-Z]{2,4}/[0-9]{3,6})$#';

    public static function normalize(?string $value): string
    {
        return strtoupper(trim((string) $value));
    }

    public static function isValid(?string $value): bool
    {
        return preg_match(self::PATTERN, self::normalize($value)) === 1;
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

        return ($match[1] ?? '') !== '' ? $match[1] !== 'ND' : $match[2] === 'HND';
    }
}
