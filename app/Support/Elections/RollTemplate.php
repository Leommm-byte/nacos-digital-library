<?php

namespace App\Support\Elections;

use App\Enums\Level;
use App\Enums\Programme;
use RuntimeException;
use ZipArchive;

/**
 * The nominal roll template (resources/templates/nominal-roll.xlsx, made by
 * hand with dropdowns and checks) with a class's programme and level filled
 * in, for an admin to send to that class's governor.
 */
class RollTemplate
{
    /**
     * Writes the filled template to a temporary file and returns its path.
     */
    public static function make(?Programme $programme, ?Level $level): string
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
        $xml = str_replace(
            ['{{PROGRAMME}}', '{{LEVEL}}'],
            [htmlspecialchars($programme?->label() ?? '', ENT_XML1), htmlspecialchars($level?->label() ?? '', ENT_XML1)],
            $xml,
        );
        $zip->addFromString($sheet, $xml);
        $zip->close();

        return $path;
    }

    public static function filename(?Programme $programme, ?Level $level): string
    {
        $class = trim(($level?->label() ?? '').' '.($programme?->label() ?? ''));

        return 'nominal-roll'.($class !== '' ? '-'.str_replace(' ', '-', strtolower($class)) : '').'.xlsx';
    }
}
