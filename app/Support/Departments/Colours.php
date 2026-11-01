<?php

namespace App\Support\Departments;

/**
 * The colours a department's space can take (docs/department-elections.md).
 * A fixed palette, each with white text that passes contrast in light and
 * dark mode; the Content-Security-Policy rules out inline styles, so each
 * is a class (.brand-{key}, resources/css/components.css) that sets the
 * primary colour tokens. No colour means NACOS green.
 */
final class Colours
{
    /** @var array<string, string> key => label */
    public const ALL = [
        'blue' => 'Blue',
        'violet' => 'Violet',
        'red' => 'Red',
        'orange' => 'Orange',
        'teal' => 'Teal',
    ];

    /** The body class for a colour, or none for NACOS green. */
    public static function bodyClass(?string $colour): ?string
    {
        return $colour !== null && isset(self::ALL[$colour]) ? 'brand-'.$colour : null;
    }
}
