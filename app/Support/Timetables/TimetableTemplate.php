<?php

namespace App\Support\Timetables;

use App\Support\Classes\SchoolClass;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * The timetable spreadsheets (resources/templates/timetable.xlsx and
 * exam-timetable.xlsx, with a day dropdown and a "How to fill this in"
 * sheet), the class timetable with its class filled in.
 */
class TimetableTemplate
{
    public static function classTimetable(?SchoolClass $class): string
    {
        return self::copy('timetable.xlsx', ['{{CLASS}}' => $class?->label() ?? '']);
    }

    public static function exams(): string
    {
        return self::copy('exam-timetable.xlsx', []);
    }

    /** "timetable-hnd1-swd-full-time.xlsx" */
    public static function filename(string $base, ?string $label = null): string
    {
        return $base.($label !== null ? '-'.Str::slug($label) : '').'.xlsx';
    }

    /**
     * @param  array<string, string>  $replace
     */
    private static function copy(string $template, array $replace): string
    {
        $path = tempnam(sys_get_temp_dir(), 'timetable');

        if ($path === false || ! copy(resource_path('templates/'.$template), $path)) {
            throw new RuntimeException('Could not prepare the template.');
        }

        if ($replace === []) {
            return $path;
        }

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException('Could not open the template.');
        }

        $sheet = 'xl/worksheets/sheet1.xml';
        $xml = (string) $zip->getFromName($sheet);
        $xml = strtr($xml, array_map(fn (string $value) => htmlspecialchars($value, ENT_XML1), $replace));
        $zip->addFromString($sheet, $xml);
        $zip->close();

        return $path;
    }
}
