<?php

namespace App\Enums;

enum Programme: string
{
    case FullTime = 'full_time';
    case PartTime = 'part_time';
    case Codfel = 'codfel';

    public function label(): string
    {
        return match ($this) {
            self::FullTime => 'Full-time',
            self::PartTime => 'Part-time',
            self::Codfel => 'CODFEL',
        };
    }

    /**
     * The letter matric numbers start with for this programme.
     */
    public function matricLetter(): string
    {
        return match ($this) {
            self::FullTime => 'F',
            self::PartTime => 'P',
            self::Codfel => 'D',
        };
    }

    /**
     * Reads a programme typed into a spreadsheet: "Full-time", "full time",
     * "FT", "Part time", "CODFEL"…
     */
    public static function fromLabel(string $value): ?self
    {
        return match (strtolower((string) preg_replace('/[^a-z]/i', '', $value))) {
            'fulltime', 'ft', 'full' => self::FullTime,
            'parttime', 'pt', 'part' => self::PartTime,
            'codfel', 'd' => self::Codfel,
            default => self::tryFrom($value),
        };
    }
}
