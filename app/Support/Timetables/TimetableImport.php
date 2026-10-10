<?php

namespace App\Support\Timetables;

use App\Models\TimetableSlot;
use App\Support\Classes\SchoolClass;

/**
 * Reads a class's weekly timetable from spreadsheet rows (see
 * SpreadsheetRows): optionally "Class: HND1 SWD Full-time" at the top, a
 * heading row, then one lecture per row with its day, start and end (or
 * one "8:00 – 10:00" time cell), course code, title, lecturer and venue.
 * A row without a day takes the day of the row above, as merged day cells
 * read.
 */
class TimetableImport
{
    private const FIELDS = [
        'day' => ['day'],
        'code' => ['code', 'course'],
        'lecturer' => ['lecturer', 'staff', 'teacher', 'tutor'],
        'venue' => ['venue', 'room', 'hall', 'location', 'lab'],
        'title' => ['title', 'name', 'subject'],
        'start' => ['start', 'from', 'begin'],
        'end' => ['end', 'to', 'finish'],
        'time' => ['time', 'period'],
    ];

    /**
     * @param  list<list<string>>  $lines
     * @return array{
     *     class: SchoolClass|null,
     *     slots: list<array{day: int, starts_at: string, ends_at: string, course_code: string, course_title: string|null, lecturer: string|null, venue: string|null}>,
     *     skipped: list<array{line: int, text: string, reason: string}>,
     *     headings: bool,
     * }
     */
    public static function parse(array $lines): array
    {
        $class = null;
        $columns = null;
        $start = 0;

        foreach (array_slice($lines, 0, 20) as $index => $cells) {
            if (str_starts_with(strtolower(trim($cells[0] ?? '')), 'class')) {
                // The first filled cell after the label (the template has a
                // hint further along).
                $value = current(array_filter(array_slice($cells, 1), fn (string $cell) => trim($cell) !== ''));
                $class = $value !== false ? self::classFrom($value) : null;
            } elseif (($found = Columns::find($cells, self::FIELDS, ['day', 'code'])) !== null) {
                $columns = $found;
                $start = $index + 1;

                break;
            }
        }

        $slots = [];
        $skipped = [];

        if ($columns === null) {
            return ['class' => $class, 'slots' => [], 'skipped' => [], 'headings' => false];
        }

        $day = null;

        foreach (array_slice($lines, $start, null, true) as $index => $cells) {
            if (implode('', $cells) === '') {
                continue;
            }

            $text = mb_strimwidth(implode(', ', array_filter($cells)), 0, 80, '…');
            $dayCell = Columns::value($cells, $columns, 'day');
            $day = $dayCell !== null ? TimetableSlot::dayFrom($dayCell) : $day;
            $code = Columns::courseCode(Columns::value($cells, $columns, 'code'));

            if ($code === null) {
                // A day name alone (a heading for the rows under it) is fine.
                if ($dayCell === null || $day === null) {
                    $skipped[] = ['line' => $index + 1, 'text' => $text, 'reason' => 'No course code'];
                }

                continue;
            }

            if ($day === null) {
                $skipped[] = ['line' => $index + 1, 'text' => $text, 'reason' => 'No day (Monday to Saturday)'];

                continue;
            }

            [$from, $to] = self::times($cells, $columns);

            if ($from === null || $to === null || $to <= $from) {
                $skipped[] = ['line' => $index + 1, 'text' => $text, 'reason' => 'Start and end times missing or the wrong way round'];

                continue;
            }

            $slots[] = [
                'day' => $day,
                'starts_at' => $from,
                'ends_at' => $to,
                'course_code' => $code,
                'course_title' => Columns::value($cells, $columns, 'title'),
                'lecturer' => Columns::value($cells, $columns, 'lecturer'),
                'venue' => Columns::value($cells, $columns, 'venue', 100),
            ];
        }

        return ['class' => $class, 'slots' => $slots, 'skipped' => $skipped, 'headings' => true];
    }

    /**
     * @param  list<string>  $cells
     * @param  array<string, int>  $columns
     * @return array{0: string|null, 1: string|null}
     */
    public static function times(array $cells, array $columns): array
    {
        $from = TimeOfDay::parse(Columns::value($cells, $columns, 'start'));
        $to = TimeOfDay::parse(Columns::value($cells, $columns, 'end'));

        if (($from === null || $to === null) && ($range = TimeOfDay::range(Columns::value($cells, $columns, 'time'))) !== null) {
            return $range;
        }

        return [$from, $to];
    }

    /**
     * A class written as its label: "HND1 SWD Full-time".
     */
    public static function classFrom(string $value): ?SchoolClass
    {
        $value = strtolower(trim((string) preg_replace('/\s+/', ' ', $value)));

        foreach (SchoolClass::all() as $class) {
            if ($value === strtolower($class->label())) {
                return $class;
            }
        }

        return null;
    }
}
