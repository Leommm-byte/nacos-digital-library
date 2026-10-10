<?php

namespace App\Support\Classes;

use App\Enums\Level;
use App\Enums\Programme;

/**
 * One class: a programme, a level and, where the stage is split into
 * courses, an arm. "HND1 SWD Full-time". Its key ("full_time|HND1|swd")
 * goes in forms and URLs.
 */
final class SchoolClass
{
    public function __construct(
        public readonly Programme $programme,
        public readonly Level $level,
        public readonly ?string $arm = null,
    ) {}

    /**
     * Every class the school runs, in order: by programme, then level,
     * then arm. Programmes only have the stages and years they offer.
     *
     * @return list<self>
     */
    public static function all(): array
    {
        $classes = [];

        foreach (Programme::cases() as $programme) {
            foreach (self::levelsFor($programme) as $level) {
                $arms = array_keys(Arms::forStage($level->stage()));

                foreach ($arms === [] ? [null] : $arms as $arm) {
                    $classes[] = new self($programme, $level, $arm);
                }
            }
        }

        return $classes;
    }

    /**
     * The levels a programme runs: full-time ND1, ND2, HND1, HND2.
     *
     * @return list<Level>
     */
    public static function levelsFor(Programme $programme): array
    {
        $levels = [];

        foreach ((array) config("classes.years.{$programme->value}", []) as $stage => $years) {
            for ($year = 1; $year <= (int) $years; $year++) {
                if (($level = Level::at((string) $stage, $year)) !== null) {
                    $levels[] = $level;
                }
            }
        }

        return $levels;
    }

    /**
     * How many years a programme's stage lasts (null when not offered).
     */
    public static function years(Programme $programme, string $stage): ?int
    {
        $years = config("classes.years.{$programme->value}.{$stage}");

        return $years === null ? null : (int) $years;
    }

    /**
     * A class from its parts; null when the school doesn't run it (CODFEL
     * HND, an arm on ND, an HND class without its arm).
     */
    public static function make(?Programme $programme, ?Level $level, ?string $arm = null): ?self
    {
        if ($programme === null || $level === null) {
            return null;
        }

        $arm = $arm === '' ? null : $arm;
        $arms = Arms::forStage($level->stage());

        if (! in_array($level, self::levelsFor($programme), true)
            || ($arms === [] && $arm !== null)
            || ($arms !== [] && ($arm === null || ! isset($arms[$arm])))) {
            return null;
        }

        return new self($programme, $level, $arm);
    }

    public static function fromKey(?string $key): ?self
    {
        [$programme, $level, $arm] = array_pad(explode('|', (string) $key, 3), 3, '');

        return self::make(Programme::tryFrom($programme), Level::tryFrom($level), $arm);
    }

    public function key(): string
    {
        return $this->programme->value.'|'.$this->level->value.'|'.($this->arm ?? '');
    }

    /** "HND1 SWD Full-time" */
    public function label(): string
    {
        return trim($this->level->label().' '.Arms::short($this->arm)).' '.$this->programme->label();
    }

    /** "HND1 SWD" */
    public function shortLabel(): string
    {
        return trim($this->level->label().' '.Arms::short($this->arm));
    }
}
