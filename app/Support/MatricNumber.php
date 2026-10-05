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
}
