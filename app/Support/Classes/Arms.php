<?php

namespace App\Support\Classes;

use App\Support\MatricNumber;

/**
 * Courses (arms) a stage is split into, from config/classes.php: HND
 * computing is Software and Web Development (SWD) or Networking and Cloud
 * Computing (NCC). A student's arm is read from their matric number, so
 * nobody has to choose it.
 */
class Arms
{
    /**
     * @return array<string, array{stage: string, digit: string, short: string, name: string}>
     */
    public static function all(): array
    {
        /** @var array<string, array{stage: string, digit: string, short: string, name: string}> $arms */
        $arms = (array) config('classes.arms', []);

        return $arms;
    }

    /**
     * The arms of a stage (ND or HND), keyed by arm.
     *
     * @return array<string, array{stage: string, digit: string, short: string, name: string}>
     */
    public static function forStage(string $stage): array
    {
        return array_filter(self::all(), fn (array $arm) => $arm['stage'] === $stage);
    }

    public static function exists(?string $arm): bool
    {
        return $arm !== null && isset(self::all()[$arm]);
    }

    /** "SWD" */
    public static function short(?string $arm): string
    {
        return $arm !== null ? (self::all()[$arm]['short'] ?? strtoupper($arm)) : '';
    }

    /** "Software and Web Development" */
    public static function name(?string $arm): string
    {
        return $arm !== null ? (self::all()[$arm]['name'] ?? strtoupper($arm)) : '';
    }

    /**
     * How the arm is read, for forms: "The course is the 4th of the last
     * seven digits: 1 for SWD, 2 for NCC."
     */
    public static function hint(): string
    {
        $position = (int) config('classes.arm_digit', 4);
        $suffix = match ($position) {
            1 => 'st',
            2 => 'nd',
            3 => 'rd',
            default => 'th',
        };
        $digits = array_map(fn (array $arm) => $arm['digit'].' for '.$arm['short'], array_values(self::all()));

        return "The course is the {$position}{$suffix} of the last seven digits of the matric number: ".implode(', ', $digits).'.';
    }

    /**
     * The arm a matric number belongs to: its stage (ND or HND) has arms
     * and the arm digit matches one. Null otherwise.
     */
    public static function fromMatric(?string $matric): ?string
    {
        $matric = MatricNumber::normalize($matric);

        if (! preg_match('#^[FPD]/(ND|HND|HD)/[0-9]{2}/([0-9]{7})$#', $matric, $match)) {
            return null;
        }

        $stage = $match[1] === 'ND' ? 'ND' : 'HND';
        $digit = $match[2][(int) config('classes.arm_digit', 4) - 1] ?? '';

        foreach (self::forStage($stage) as $key => $arm) {
            if ($arm['digit'] === $digit) {
                return $key;
            }
        }

        return null;
    }
}
