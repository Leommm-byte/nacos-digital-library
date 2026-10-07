<?php

namespace App\Support;

/**
 * YabaTech matric number formats, as accepted by the original app:
 *   F/ND/24/1234567    programme letter (F full-time, P part-time, C CODFEL),
 *                      ND/HND/HD, two-digit entry year (19 onwards), number
 *   ND/2019/CS/1234    older format: level, year, department code, number
 */
class MatricNumber
{
    public const PATTERN = '#^(?:[FPC]/(?:ND|HND|HD)/(?:19|[2-9][0-9])/[0-9]{3,10}|(?:ND|HND)/[0-9]{4}/[A-Z]{2,4}/[0-9]{3,6})$#';

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

        if (preg_match('#^[FPC]/(?:ND|HND|HD)/([0-9]{2})/#', $value, $match)) {
            return 2000 + (int) $match[1];
        }

        if (preg_match('#^(?:ND|HND)/([0-9]{4})/#', $value, $match)) {
            return (int) $match[1];
        }

        return null;
    }
}
