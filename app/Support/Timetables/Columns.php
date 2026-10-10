<?php

namespace App\Support\Timetables;

/**
 * Finds a timetable spreadsheet's columns by their headings ("Day",
 * "Course code", "Venue"…), shared by the class and exam imports.
 */
class Columns
{
    /**
     * Column positions for each field whose heading matches one of its
     * words, or null when the row isn't a heading row.
     *
     * @param  list<string>  $cells
     * @param  array<string, list<string>>  $fields  field => heading words
     * @param  list<string>  $required  fields the heading row must have
     * @return array<string, int>|null
     */
    public static function find(array $cells, array $fields, array $required): ?array
    {
        $columns = [];

        // Each field takes the first heading matching its first word, then
        // its second…: "Course code" wins over "Course title" for the code.
        foreach ($fields as $field => $words) {
            foreach ($words as $word) {
                foreach ($cells as $index => $cell) {
                    if (! in_array($index, $columns, true) && self::matches(strtolower(trim($cell)), $word)) {
                        $columns[$field] = $index;

                        continue 3;
                    }
                }
            }
        }

        foreach ($required as $field) {
            if (! isset($columns[$field])) {
                return null;
            }
        }

        return $columns;
    }

    /**
     * Short words match whole words only ("to" isn't in "tutor"); longer
     * ones anywhere ("room" is in "Classroom").
     */
    private static function matches(string $heading, string $word): bool
    {
        if ($heading === '') {
            return false;
        }

        return strlen($word) <= 3
            ? preg_match('/\b'.preg_quote($word, '/').'\b/', $heading) === 1
            : str_contains($heading, $word);
    }

    /**
     * @param  list<string>  $cells
     * @param  array<string, int>  $columns
     */
    public static function value(array $cells, array $columns, string $field, int $max = 150): ?string
    {
        $value = isset($columns[$field]) ? trim((string) preg_replace('/\s+/', ' ', $cells[$columns[$field]] ?? '')) : '';

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /** "csc 201" → "CSC 201" */
    public static function courseCode(?string $value): ?string
    {
        $value = strtoupper(trim((string) preg_replace('/\s+/', ' ', (string) $value)));

        return $value === '' ? null : mb_substr($value, 0, 20);
    }
}
