<?php

namespace App\Support\Spreadsheets;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Reads the rows of a CSV or XLSX file as lists of strings, without a
 * spreadsheet library (shared hosting, and only plain cell values are
 * needed). For XLSX only the first worksheet is read.
 */
class SpreadsheetRows
{
    public const MAX_ROWS = 20000;

    /**
     * @return list<list<string>>
     */
    public static function read(string $path, string $extension): array
    {
        return strtolower($extension) === 'xlsx' ? self::xlsx($path) : self::csv($path);
    }

    /**
     * @return list<list<string>>
     */
    public static function csv(string $path): array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException('The file could not be opened.');
        }

        $delimiter = self::delimiter((string) fgets($handle));
        rewind($handle);

        $rows = [];

        while (count($rows) < self::MAX_ROWS && ($cells = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            $rows[] = array_map(fn ($cell) => self::clean((string) $cell), array_values($cells));
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @return list<list<string>>
     */
    public static function xlsx(string $path): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException('This doesn\'t look like an Excel file.');
        }

        try {
            $strings = self::sharedStrings($zip);
            $sheet = self::xml($zip, self::firstSheetPath($zip));

            if ($sheet === null) {
                throw new RuntimeException('The Excel file has no worksheet.');
            }

            $rows = [];
            $sheet->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

            foreach ($sheet->xpath('//m:sheetData/m:row') ?: [] as $row) {
                if (count($rows) >= self::MAX_ROWS) {
                    break;
                }

                $number = (int) self::attribute($row, 'r');
                // Keep row positions, so empty rows stay empty.
                while ($number > 0 && count($rows) < $number - 1) {
                    $rows[] = [];
                }

                $cells = [];
                foreach ($row->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main')->c as $cell) {
                    $column = self::columnIndex(self::attribute($cell, 'r')) ?? count($cells);
                    while (count($cells) < $column) {
                        $cells[] = '';
                    }
                    $cells[] = self::clean(self::cellValue($cell, $strings));
                }

                $rows[] = $cells;
            }

            return $rows;
        } finally {
            $zip->close();
        }
    }

    /**
     * @param  list<string>  $strings
     */
    private static function cellValue(SimpleXMLElement $cell, array $strings): string
    {
        $main = $cell->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $value = isset($main->v) ? (string) $main->v : '';

        return match (self::attribute($cell, 't')) {
            's' => $strings[(int) $value] ?? '',
            'inlineStr' => isset($main->is) ? self::text($main->is) : '',
            'b' => $value === '1' ? 'TRUE' : 'FALSE',
            // Whole numbers typed into a cell are stored as 1234567 or 1.0E+3.
            default => is_numeric($value) && (float) $value === floor((float) $value) && abs((float) $value) < 1e15
                ? number_format((float) $value, 0, '.', '')
                : $value,
        };
    }

    /**
     * @return list<string>
     */
    private static function sharedStrings(ZipArchive $zip): array
    {
        $xml = self::xml($zip, 'xl/sharedStrings.xml');

        if ($xml === null) {
            return [];
        }

        $strings = [];
        foreach ($xml->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main')->si as $item) {
            $strings[] = self::text($item);
        }

        return $strings;
    }

    /**
     * Text of a string item, joining rich-text runs.
     */
    private static function text(SimpleXMLElement $item): string
    {
        $item->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        return implode('', array_map(fn ($t) => (string) $t, $item->xpath('.//m:t') ?: []));
    }

    private static function firstSheetPath(ZipArchive $zip): string
    {
        $workbook = self::xml($zip, 'xl/workbook.xml');
        $relations = self::xml($zip, 'xl/_rels/workbook.xml.rels');

        if ($workbook !== null && $relations !== null) {
            $sheet = $workbook->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main')->sheets->sheet[0] ?? null;
            $attributes = $sheet?->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $id = $attributes !== null ? (string) ($attributes['id'] ?? '') : '';

            foreach ($relations->children('http://schemas.openxmlformats.org/package/2006/relationships')->Relationship as $relation) {
                if (self::attribute($relation, 'Id') === $id) {
                    $target = ltrim(self::attribute($relation, 'Target'), '/');

                    return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
                }
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    /**
     * An attribute without a namespace (r, t, Id…), which plain array access
     * can't reach on elements read through a namespace.
     */
    private static function attribute(SimpleXMLElement $element, string $name): string
    {
        return (string) ($element->attributes()[$name] ?? '');
    }

    private static function xml(ZipArchive $zip, string $name): ?SimpleXMLElement
    {
        $content = $zip->getFromName($name);

        if ($content === false) {
            return null;
        }

        // No entity substitution or network access while parsing.
        $xml = simplexml_load_string($content, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);

        return $xml === false ? null : $xml;
    }

    /**
     * "C12" → 2 (zero-based column).
     */
    private static function columnIndex(string $reference): ?int
    {
        if (! preg_match('/^([A-Z]+)/', $reference, $match)) {
            return null;
        }

        $index = 0;
        foreach (str_split($match[1]) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index - 1;
    }

    private static function delimiter(string $line): string
    {
        $counts = [',' => substr_count($line, ','), ';' => substr_count($line, ';'), "\t" => substr_count($line, "\t")];
        arsort($counts);

        return (string) array_key_first($counts);
    }

    /**
     * Trims spaces, including the byte order mark and non-breaking spaces
     * spreadsheets leave behind.
     */
    private static function clean(string $value): string
    {
        return preg_replace('/^[\s\x{FEFF}\x{A0}]+|[\s\x{FEFF}\x{A0}]+$/u', '', $value) ?? trim($value);
    }
}
