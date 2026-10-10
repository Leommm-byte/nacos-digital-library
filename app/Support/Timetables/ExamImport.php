<?php

namespace App\Support\Timetables;

use App\Enums\Level;
use App\Enums\Programme;
use App\Support\Classes\Arms;
use DateTimeImmutable;
use Illuminate\Support\Carbon;

/**
 * Reads the exam timetable from spreadsheet rows: a heading row, then one
 * paper per row with its date, start and end (or one time cell), course
 * code, title, venue and who sits it ("ND1", "HND2 SWD", "Part-time ND2",
 * or empty for everyone). A row without a date takes the date above.
 */
class ExamImport
{
    private const FIELDS = [
        'date' => ['date', 'day'],
        'code' => ['code', 'course', 'paper'],
        'venue' => ['venue', 'room', 'hall', 'location', 'lab'],
        'for' => ['for', 'class', 'level', 'who'],
        'note' => ['note', 'remark', 'comment'],
        'title' => ['title', 'name', 'subject'],
        'start' => ['start', 'from', 'begin'],
        'end' => ['end', 'to', 'finish'],
        'time' => ['time', 'period'],
    ];

    /**
     * @param  list<list<string>>  $lines
     * @return array{
     *     exams: list<array{date: string, starts_at: string, ends_at: string|null, course_code: string, course_title: string|null, venue: string|null, levels: list<string>|null, programmes: list<string>|null, arms: list<string>|null, note: string|null}>,
     *     skipped: list<array{line: int, text: string, reason: string}>,
     *     headings: bool,
     * }
     */
    public static function parse(array $lines): array
    {
        $columns = null;
        $start = 0;

        foreach (array_slice($lines, 0, 20) as $index => $cells) {
            if (($found = Columns::find($cells, self::FIELDS, ['date', 'code'])) !== null) {
                $columns = $found;
                $start = $index + 1;

                break;
            }
        }

        if ($columns === null) {
            return ['exams' => [], 'skipped' => [], 'headings' => false];
        }

        $exams = [];
        $skipped = [];
        $date = null;

        foreach (array_slice($lines, $start, null, true) as $index => $cells) {
            if (implode('', $cells) === '') {
                continue;
            }

            $text = mb_strimwidth(implode(', ', array_filter($cells)), 0, 80, '…');
            $dateCell = Columns::value($cells, $columns, 'date');
            $date = $dateCell !== null ? self::date($dateCell) : $date;
            $code = Columns::courseCode(Columns::value($cells, $columns, 'code'));

            if ($code === null) {
                if ($dateCell === null || $date === null) {
                    $skipped[] = ['line' => $index + 1, 'text' => $text, 'reason' => 'No course code'];
                }

                continue;
            }

            if ($date === null) {
                $skipped[] = ['line' => $index + 1, 'text' => $text, 'reason' => 'No date, or one that can\'t be read (write it like 3/11/2026)'];

                continue;
            }

            [$from, $to] = TimetableImport::times($cells, $columns);

            if ($from === null || ($to !== null && $to <= $from)) {
                $skipped[] = ['line' => $index + 1, 'text' => $text, 'reason' => 'No start time, or the end is before it'];

                continue;
            }

            $audience = self::audience(Columns::value($cells, $columns, 'for'));

            if ($audience === null) {
                $skipped[] = ['line' => $index + 1, 'text' => $text, 'reason' => 'Who sits it can\'t be read (write it like ND1, HND2 SWD or Part-time ND2)'];

                continue;
            }

            $exams[] = [
                'date' => $date->toDateString(),
                'starts_at' => $from,
                'ends_at' => $to,
                'course_code' => $code,
                'course_title' => Columns::value($cells, $columns, 'title'),
                'venue' => Columns::value($cells, $columns, 'venue', 100),
                ...$audience,
                'note' => Columns::value($cells, $columns, 'note', 200),
            ];
        }

        return ['exams' => $exams, 'skipped' => $skipped, 'headings' => true];
    }

    /**
     * A date as typed ("3/11/2026", "2026-11-03", "Tue 3 Nov 2026", "3rd
     * November 2026") or an Excel date (days since 1899-12-30). Day before
     * month, as written in Nigeria.
     */
    public static function date(?string $value): ?Carbon
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (ctype_digit($value) && (int) $value > 30000 && (int) $value < 80000) {
            return Carbon::parse('1899-12-30')->addDays((int) $value);
        }

        // Drop the weekday and ordinals: "Tuesday, 3rd Nov 2026" → "3 Nov 2026".
        $value = (string) preg_replace('/^(mon|tue|wed|thu|fri|sat|sun)[a-z]*\.?,?\s+/i', '', $value);
        $value = (string) preg_replace('/(\d)(st|nd|rd|th)\b/i', '$1', $value);
        $value = trim((string) preg_replace('/\s+/', ' ', str_replace(',', ' ', $value)));

        foreach (['!Y-m-d', '!j/n/Y', '!j-n-Y', '!j.n.Y', '!j/n/y', '!j M Y', '!j F Y', '!M j Y', '!F j Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            $errors = DateTimeImmutable::getLastErrors();

            // No overflow either: 31/02 isn't 3 March.
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
                && (int) $date->format('Y') >= 2000 && (int) $date->format('Y') <= 2100) {
                return Carbon::instance($date);
            }
        }

        return null;
    }

    /**
     * Who sits a paper: levels, programmes and courses read from words
     * like "HND1 SWD" or "Part-time ND1, ND2"; all empty for everyone; null
     * when a word isn't any of them.
     *
     * @return array{levels: list<string>|null, programmes: list<string>|null, arms: list<string>|null}|null
     */
    public static function audience(?string $value): ?array
    {
        $value = strtolower(trim((string) $value));
        $value = (string) preg_replace(['/full[\s-]*time/', '/part[\s-]*time/'], ['fulltime', 'parttime'], $value);
        $levels = [];
        $programmes = [];
        $arms = [];

        foreach (preg_split('/[\s,;\/&+]+/', $value) ?: [] as $word) {
            if ($word === '' || in_array($word, ['all', 'everyone', 'and', 'students'], true)) {
                continue;
            }

            if (($level = Level::tryFrom(strtoupper($word))) !== null) {
                $levels[] = $level->value;
            } elseif (($arm = self::arm($word)) !== null) {
                $arms[] = $arm;
            } elseif (($programme = Programme::fromLabel($word)) !== null) {
                $programmes[] = $programme->value;
            } else {
                return null;
            }
        }

        $list = fn (array $values) => $values === [] ? null : array_values(array_unique($values));

        return ['levels' => $list($levels), 'programmes' => $list($programmes), 'arms' => $list($arms)];
    }

    private static function arm(string $word): ?string
    {
        foreach (Arms::all() as $key => $arm) {
            if ($word === $key || $word === strtolower($arm['short'])) {
                return $key;
            }
        }

        return null;
    }
}
