<?php

namespace App\Support\Elections;

use App\Enums\Level;
use App\Enums\Programme;
use App\Models\RollEntry;
use App\Support\Classes\Arms;
use App\Support\Classes\SchoolClass;
use App\Support\MatricNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Reads one class's nominal roll from spreadsheet rows (CSV or XLSX, see
 * SpreadsheetRows) and saves it. The roll is kept class by class: saving a
 * class replaces that class only.
 *
 * The template has the class at the top ("Programme: Full-time",
 * "Level: HND1", and "Course: SWD" where the stage is split into arms),
 * then a heading row, then one student per row. Columns are found by
 * their heading ("matric", "name", "email"); a file without headings is
 * read by what the cells look like.
 */
class RollImport
{
    /**
     * @param  list<list<string>>  $lines
     * @return array{
     *     rows: array<string, array{matric_number: string, fullname: string|null, email: string|null}>,
     *     skipped: list<array{line: int, text: string}>,
     *     programme: Programme|null,
     *     level: Level|null,
     *     arm: string|null,
     * }
     */
    public static function parse(array $lines): array
    {
        $programme = null;
        $level = null;
        $arm = null;
        $columns = null;
        $start = 0;

        // The class and the heading row are near the top.
        foreach (array_slice($lines, 0, 20) as $index => $cells) {
            $label = strtolower($cells[0] ?? '');
            $value = self::firstValue($cells);

            if (str_contains($label, 'programme') || str_contains($label, 'program')) {
                $programme = $value !== '' ? Programme::fromLabel($value) : null;
            } elseif (str_starts_with($label, 'level') || str_starts_with($label, 'class')) {
                $level = $value !== '' ? self::level($value) : null;
            } elseif (str_starts_with($label, 'course') || str_starts_with($label, 'arm')) {
                $arm = $value !== '' ? self::arm($value) : null;
            } elseif (($header = self::header($cells)) !== null) {
                $columns = $header;
                $start = $index + 1;

                break;
            }
        }

        $rows = [];
        $skipped = [];

        foreach (array_slice($lines, $start, null, true) as $index => $cells) {
            if (implode('', $cells) === '') {
                continue;
            }

            $row = $columns !== null ? self::byHeading($cells, $columns) : self::byShape($cells);

            if ($row === null) {
                // Without headings, the class lines above are not students.
                $label = strtolower($cells[0] ?? '');
                if ($columns === null && (str_contains($label, 'programme') || str_starts_with($label, 'level') || str_starts_with($label, 'course'))) {
                    continue;
                }

                $skipped[] = ['line' => $index + 1, 'text' => mb_strimwidth(implode(', ', array_filter($cells)), 0, 80, '…')];

                continue;
            }

            // A repeated matric number keeps its first row.
            $rows[$row['matric_number']] ??= $row;
        }

        return ['rows' => $rows, 'skipped' => $skipped, 'programme' => $programme, 'level' => $level, 'arm' => $arm];
    }

    /**
     * Rows whose matric number belongs to another kind of class: the
     * programme letter (F, P, D), ND/HND or the arm digit doesn't match.
     *
     * @param  array<string, array{matric_number: string, fullname: string|null, email: string|null}>  $rows
     * @return list<string>
     */
    public static function mismatches(array $rows, SchoolClass $class): array
    {
        $hnd = $class->level->stage() === 'HND';
        $wrong = [];

        foreach (array_keys($rows) as $matric) {
            $letter = MatricNumber::programme($matric);
            $isHnd = MatricNumber::isHnd($matric);

            if (($letter !== null && $letter !== $class->programme)
                || ($isHnd !== null && $isHnd !== $hnd)
                || ($class->arm !== null && $isHnd === $hnd && Arms::fromMatric($matric) !== $class->arm)) {
                $wrong[] = $matric;
            }
        }

        return $wrong;
    }

    /**
     * Saves rows into one class. With $replace they become the whole list
     * for the class and students no longer on it are removed from the roll;
     * without, they are added and nobody is removed. Either way students
     * listed under another class move to this one.
     *
     * @param  array<string, array{matric_number: string, fullname: string|null, email: string|null}>  $rows
     * @return array{total: int, added: int, removed: int, moved: int}
     */
    public static function saveClass(array $rows, SchoolClass $class, bool $replace = true): array
    {
        return DB::transaction(function () use ($rows, $class, $replace) {
            $incoming = array_keys($rows);
            $inClass = self::inClass($class)->pluck('matric_number')->all();

            $moved = 0;
            foreach (array_chunk($incoming, 500) as $chunk) {
                $moved += RollEntry::query()->whereIn('matric_number', $chunk)
                    ->whereNotIn('id', self::inClass($class)->select('id'))
                    ->count();
            }

            $removed = $replace ? array_values(array_diff($inClass, $incoming)) : [];
            foreach (array_chunk($removed, 500) as $chunk) {
                RollEntry::query()->whereIn('matric_number', $chunk)->delete();
            }

            $now = now();
            foreach (array_chunk(array_values($rows), 500) as $chunk) {
                RollEntry::query()->upsert(
                    array_map(fn (array $row) => [
                        ...$row,
                        'programme' => $class->programme->value,
                        'level' => $class->level->value,
                        'arm' => $class->arm,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ], $chunk),
                    ['matric_number'],
                    ['fullname', 'email', 'programme', 'level', 'arm', 'updated_at'],
                );
            }

            return [
                'total' => $replace ? count($incoming) : count(array_unique([...$inClass, ...$incoming])),
                'added' => count(array_diff($incoming, $inClass)) - $moved,
                'removed' => count($removed),
                'moved' => $moved,
            ];
        });
    }

    /**
     * The roll entries of one class.
     *
     * @return Builder<RollEntry>
     */
    public static function inClass(SchoolClass $class): Builder
    {
        return RollEntry::query()
            ->where('programme', $class->programme)
            ->where('level', $class->level)
            ->when($class->arm === null, fn ($query) => $query->whereNull('arm'), fn ($query) => $query->where('arm', $class->arm));
    }

    /**
     * Column positions when this is the heading row.
     *
     * @param  list<string>  $cells
     * @return array{matric: int, name: int|null, email: int|null}|null
     */
    private static function header(array $cells): ?array
    {
        $matric = self::find($cells, ['matric', 'reg']);

        if ($matric === null || MatricNumber::isValid($cells[$matric])) {
            return null;
        }

        return ['matric' => $matric, 'name' => self::find($cells, ['name']), 'email' => self::find($cells, ['mail'])];
    }

    /**
     * @param  list<string>  $cells
     * @param  array{matric: int, name: int|null, email: int|null}  $columns
     * @return array{matric_number: string, fullname: string|null, email: string|null}|null
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
            'email' => self::email($columns['email'] !== null ? ($cells[$columns['email']] ?? '') : ''),
        ];
    }

    /**
     * Without headings: the cell that is a matric number, a cell that is an
     * email address, and the first other text as the name.
     *
     * @param  list<string>  $cells
     * @return array{matric_number: string, fullname: string|null, email: string|null}|null
     */
    private static function byShape(array $cells): ?array
    {
        $matric = null;
        $name = null;
        $email = null;

        foreach ($cells as $cell) {
            if ($matric === null && MatricNumber::isValid($cell)) {
                $matric = MatricNumber::normalize($cell);
            } elseif ($email === null && self::email($cell) !== null) {
                $email = self::email($cell);
            } elseif ($name === null && $cell !== '' && ! is_numeric($cell) && self::level($cell) === null) {
                $name = self::name($cell);
            }
        }

        return $matric === null ? null : ['matric_number' => $matric, 'fullname' => $name, 'email' => $email];
    }

    /**
     * The first column whose heading contains one of the words.
     *
     * @param  list<string>  $cells
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
     * @param  list<string>  $cells
     */
    private static function firstValue(array $cells): string
    {
        foreach (array_slice($cells, 1) as $cell) {
            if ($cell !== '') {
                return $cell;
            }
        }

        return '';
    }

    public static function level(string $value): ?Level
    {
        return Level::tryFrom(strtoupper((string) preg_replace('/\s+/', '', $value)));
    }

    /**
     * Reads an arm typed into a spreadsheet: "SWD", "swd", "Software and
     * Web Development".
     */
    public static function arm(string $value): ?string
    {
        $value = strtolower(trim($value));

        foreach (Arms::all() as $key => $arm) {
            if (in_array($value, [$key, strtolower($arm['short']), strtolower($arm['name'])], true)) {
                return $key;
            }
        }

        return null;
    }

    private static function name(string $value): ?string
    {
        $value = trim((string) preg_replace('/\s+/', ' ', $value));

        return $value === '' ? null : mb_substr($value, 0, 150);
    }

    private static function email(string $value): ?string
    {
        $value = strtolower(trim($value));

        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? mb_substr($value, 0, 255) : null;
    }
}
