<?php

namespace App\Support\Elections;

use App\Enums\Level;
use App\Models\RollEntry;
use App\Support\MatricNumber;
use Illuminate\Support\Facades\DB;

/**
 * Reads a nominal roll from a CSV (or a plain list of matric numbers, one
 * per line) and saves it. Spreadsheets are exported in many shapes, so
 * columns are found by their heading ("matric", "name", "level") when there
 * is one, otherwise by what the cells look like.
 */
class RollImport
{
    public const MAX_ROWS = 20000;

    /**
     * @return array{rows: array<string, array{matric_number: string, fullname: string|null, level: string|null}>, skipped: list<array{line: int, text: string}>}
     */
    public static function parse(string $path): array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return ['rows' => [], 'skipped' => []];
        }

        $first = (string) fgets($handle);
        rewind($handle);
        $delimiter = self::delimiter($first);

        $rows = [];
        $skipped = [];
        $columns = null;
        $line = 0;

        while (($cells = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            $line++;
            $cells = array_map(fn ($cell) => self::clean((string) $cell), $cells);

            if (implode('', $cells) === '') {
                continue;
            }

            if ($line === 1 && ($header = self::header($cells)) !== null) {
                $columns = $header;

                continue;
            }

            $row = $columns !== null ? self::byHeading($cells, $columns) : self::byShape($cells);

            if ($row === null) {
                $skipped[] = ['line' => $line, 'text' => mb_strimwidth(implode(', ', array_filter($cells)), 0, 80, '…')];

                continue;
            }

            // A repeated matric number keeps its first row.
            $rows[$row['matric_number']] ??= $row;

            if (count($rows) >= self::MAX_ROWS) {
                break;
            }
        }

        fclose($handle);

        return ['rows' => $rows, 'skipped' => $skipped];
    }

    /**
     * Saves the rows. "replace" makes them the whole roll (students missing
     * from the new list, such as graduates, drop off); "add" keeps everyone
     * already on it and updates their name and level.
     *
     * @param  array<string, array{matric_number: string, fullname: string|null, level: string|null}>  $rows
     * @return array{total: int, added: int, removed: int}
     */
    public static function save(array $rows, bool $replace): array
    {
        return DB::transaction(function () use ($rows, $replace) {
            $existing = RollEntry::query()->pluck('matric_number')->all();
            $incoming = array_keys($rows);

            $added = count(array_diff($incoming, $existing));
            $removed = $replace ? array_values(array_diff($existing, $incoming)) : [];

            foreach (array_chunk($removed, 500) as $chunk) {
                RollEntry::query()->whereIn('matric_number', $chunk)->delete();
            }

            $now = now();

            foreach (array_chunk(array_values($rows), 500) as $chunk) {
                RollEntry::query()->upsert(
                    array_map(fn (array $row) => [...$row, 'created_at' => $now, 'updated_at' => $now], $chunk),
                    ['matric_number'],
                    ['fullname', 'level', 'updated_at'],
                );
            }

            return ['total' => RollEntry::query()->count(), 'added' => $added, 'removed' => count($removed)];
        });
    }

    private static function delimiter(string $line): string
    {
        $counts = [',' => substr_count($line, ','), ';' => substr_count($line, ';'), "\t" => substr_count($line, "\t")];
        arsort($counts);

        return (string) array_key_first($counts);
    }

    /**
     * Column positions when the first row is a heading row.
     *
     * @param  array<int, string>  $cells
     * @return array{matric: int, name: int|null, level: int|null}|null
     */
    private static function header(array $cells): ?array
    {
        $matric = self::find($cells, ['matric', 'reg']);

        if ($matric === null || MatricNumber::isValid($cells[$matric])) {
            return null;
        }

        return ['matric' => $matric, 'name' => self::find($cells, ['name']), 'level' => self::find($cells, ['level', 'class'])];
    }

    /**
     * @param  array<int, string>  $cells
     * @param  array{matric: int, name: int|null, level: int|null}  $columns
     * @return array{matric_number: string, fullname: string|null, level: string|null}|null
     */
    private static function byHeading(array $cells, array $columns): ?array
    {
        $matric = MatricNumber::normalize($cells[$columns['matric']] ?? '');

        if (! MatricNumber::isValid($matric)) {
            return null;
        }

        return [
            'matric_number' => $matric,
            'fullname' => self::name($columns['name'] !== null ? ($cells[$columns['name']] ?? '') : ''),
            'level' => $columns['level'] !== null ? self::level($cells[$columns['level']] ?? '') : null,
        ];
    }

    /**
     * Without headings: the cell that is a matric number, a cell that is a
     * level, and the first other text as the name.
     *
     * @param  array<int, string>  $cells
     * @return array{matric_number: string, fullname: string|null, level: string|null}|null
     */
    private static function byShape(array $cells): ?array
    {
        $matric = null;
        $level = null;
        $name = null;

        foreach ($cells as $cell) {
            if ($matric === null && MatricNumber::isValid($cell)) {
                $matric = MatricNumber::normalize($cell);
            } elseif ($level === null && self::level($cell) !== null) {
                $level = self::level($cell);
            } elseif ($name === null && $cell !== '' && ! is_numeric($cell)) {
                $name = self::name($cell);
            }
        }

        return $matric === null ? null : ['matric_number' => $matric, 'fullname' => $name, 'level' => $level];
    }

    /**
     * The first column whose heading contains one of the words.
     *
     * @param  array<int, string>  $cells
     * @param  list<string>  $words
     */
    private static function find(array $cells, array $words): ?int
    {
        foreach ($cells as $index => $cell) {
            foreach ($words as $word) {
                if (str_contains(strtolower($cell), $word)) {
                    return $index;
                }
            }
        }

        return null;
    }

    /**
     * Trims spaces, including the byte order mark and non-breaking spaces
     * spreadsheets leave behind.
     */
    private static function clean(string $value): string
    {
        return preg_replace('/^[\s\x{FEFF}\x{A0}]+|[\s\x{FEFF}\x{A0}]+$/u', '', $value) ?? trim($value);
    }

    private static function level(string $value): ?string
    {
        return Level::tryFrom(strtoupper((string) preg_replace('/\s+/', '', $value)))?->value;
    }

    private static function name(string $value): ?string
    {
        $value = trim((string) preg_replace('/\s+/', ' ', $value));

        return $value === '' ? null : mb_substr($value, 0, 150);
    }
}
