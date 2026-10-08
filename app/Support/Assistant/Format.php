<?php

namespace App\Support\Assistant;

use Illuminate\Support\HtmlString;

/**
 * Turns an answer's plain text into blocks the page draws itself:
 * paragraphs, bullet and numbered lists, and **bold** runs. Nothing in the
 * text is ever treated as HTML, so answers can't inject markup; the same
 * blocks are rendered by Blade (no-JS page) and by resources/js/assistant.js.
 */
class Format
{
    /**
     * @return list<array{type: 'p'|'ul'|'ol', items: list<list<array{text: string, bold: bool}>>}>
     */
    public static function blocks(string $text): array
    {
        $blocks = [];
        $current = null;

        foreach (preg_split('/\R/u', trim($text)) ?: [] as $line) {
            $line = rtrim($line);

            if ($line === '') {
                $current = null;

                continue;
            }

            if (preg_match('/^\s*(?:[-*•])\s+(.+)$/u', $line, $match)) {
                [$type, $content] = ['ul', $match[1]];
            } elseif (preg_match('/^\s*\d{1,2}[.)]\s+(.+)$/u', $line, $match)) {
                [$type, $content] = ['ol', $match[1]];
            } else {
                // Headings become bold lines.
                $type = 'p';
                $content = preg_match('/^#{1,6}\s+(.+)$/u', $line, $match) ? '**'.trim($match[1], '* ').'**' : $line;
            }

            if ($current !== null && $blocks[$current]['type'] === $type) {
                if ($type === 'p') {
                    // Lines of one paragraph stay on their own lines.
                    $last = count($blocks[$current]['items']) - 1;
                    $blocks[$current]['items'][$last] = [
                        ...$blocks[$current]['items'][$last],
                        ['text' => "\n", 'bold' => false],
                        ...self::inline($content),
                    ];
                } else {
                    $blocks[$current]['items'][] = self::inline($content);
                }

                continue;
            }

            $blocks[] = ['type' => $type, 'items' => [self::inline($content)]];
            $current = count($blocks) - 1;
        }

        return $blocks;
    }

    /**
     * One line of runs as HTML for Blade, every run escaped.
     *
     * @param  list<array{text: string, bold: bool}>  $runs
     */
    public static function html(array $runs): HtmlString
    {
        return new HtmlString(implode('', array_map(
            fn (array $run) => $run['bold'] ? '<strong>'.e($run['text']).'</strong>' : e($run['text']),
            $runs,
        )));
    }

    /**
     * @return list<array{text: string, bold: bool}>
     */
    private static function inline(string $text): array
    {
        $runs = [];
        $parts = explode('**', $text);

        // An odd number of "**" leaves the last one as written.
        if (count($parts) % 2 === 0) {
            $last = array_pop($parts);
            $parts[count($parts) - 1] .= '**'.$last;
        }

        foreach ($parts as $index => $part) {
            $part = str_replace('`', '', $part);
            if ($part !== '') {
                $runs[] = ['text' => $part, 'bold' => $index % 2 === 1];
            }
        }

        return $runs;
    }
}
