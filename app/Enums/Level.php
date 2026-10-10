<?php

namespace App\Enums;

enum Level: string
{
    case ND1 = 'ND1';
    case ND2 = 'ND2';
    case ND3 = 'ND3';
    case HND1 = 'HND1';
    case HND2 = 'HND2';
    case HND3 = 'HND3';

    public function label(): string
    {
        return $this->value;
    }

    /** ND or HND. */
    public function stage(): string
    {
        return str_starts_with($this->value, 'HND') ? 'HND' : 'ND';
    }

    /** The year within the stage: 1, 2 or 3. */
    public function year(): int
    {
        return (int) substr($this->value, -1);
    }

    public static function at(string $stage, int $year): ?self
    {
        return self::tryFrom($stage.$year);
    }
}
