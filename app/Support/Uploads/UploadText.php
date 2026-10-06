<?php

namespace App\Support\Uploads;

/**
 * Tidies text read from a book's pages before it is stored for search.
 */
class UploadText
{
    public static function clean(string $text): string
    {
        // Valid UTF-8 only, without control characters (except newlines).
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = (string) preg_replace('/[^\P{C}\n]+/u', ' ', $text);
        $text = (string) preg_replace('/[ \t]+/', ' ', $text);
        $text = (string) preg_replace('/ *\n */', "\n", $text);
        $text = (string) preg_replace('/\n\s*\n\s*/', "\n\n", $text);

        return mb_substr(trim($text), 0, (int) config('uploads.text_max_chars'));
    }
}
