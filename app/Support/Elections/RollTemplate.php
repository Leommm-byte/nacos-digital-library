<?php

namespace App\Support\Elections;

use App\Enums\Level;
use App\Enums\Programme;
use App\Support\Classes\Arms;
use RuntimeException;
use ZipArchive;

/**
 * The nominal roll template (resources/templates/nominal-roll.xlsx, made by
 * hand with dropdowns and checks) with a class's programme, level and course
 * (arm) filled in, for an admin to send to that class's governor.
 */
class RollTemplate
{
    /**
     * Writes the filled template to a temporary file and returns its path.
     */
    public static function make(?Programme $programme, ?Level $level, ?string $arm = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'roll');

        if ($path === false || ! copy(resource_path('templates/nominal-roll.xlsx'), $path)) {
            throw new RuntimeException('Could not prepare the template.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException('Could not open the template.');
        }

        $sheet = 'xl/worksheets/sheet1.xml';
        $xml = (string) $zip->getFromName($sheet);
        $arms = array_map(fn (array $arm) => $arm['short'], Arms::all());
        $hint = $arms === [] ? 'Leave empty' : self::joinWords($arms).' (HND only); leave empty for ND';
        $xml = str_replace(
            ['{{PROGRAMME}}', '{{LEVEL}}', '{{ARM}}', '{{ARM_HINT}}', '{{ARM_LIST}}'],
            array_map(fn (string $value) => htmlspecialchars($value, ENT_XML1), [
                $programme?->label() ?? '',
                $level?->label() ?? '',
                Arms::short($arm),
                $hint,
                implode(',', $arms),
            ]),
            $xml,
        );
        $zip->addFromString($sheet, $xml);
        $zip->close();

        return $path;
    }

    public static function filename(?Programme $programme, ?Level $level, ?string $arm = null): string
    {
        $class = implode(' ', array_filter([$level?->label(), Arms::short($arm), $programme?->label()]));

        return 'nominal-roll'.($class !== '' ? '-'.str_replace(' ', '-', strtolower($class)) : '').'.xlsx';
    }

    /**
     * @param  array<array-key, string>  $words
     */
    private static function joinWords(array $words): string
    {
        $words = array_values($words);
        $last = array_pop($words);

        return $words === [] ? (string) $last : implode(', ', $words).' or '.$last;
    }
}
