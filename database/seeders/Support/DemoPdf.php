<?php

namespace Database\Seeders\Support;

/**
 * Writes small, valid text PDFs for demo data and tests, so the reader has
 * something real to open without shipping sample books in the repository.
 */
class DemoPdf
{
    private const WIDTH = 595;

    private const HEIGHT = 842;

    /**
     * The paragraphs (body text) are repeated on every page.
     *
     * @param  list<string>  $paragraphs
     */
    public static function make(string $title, int $pages, array $paragraphs = []): string
    {
        $paragraphs = $paragraphs ?: [
            'This is a sample book generated for trying out the reader. Real books are uploaded by students and approved by reviewers.',
            'Use the arrows or your keyboard to turn pages, pinch or use the zoom buttons to get closer, and type a page number to jump straight to it.',
            'Your place is saved as you read, so the book opens where you stopped next time, on any device.',
        ];

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '', // Filled in once the page objects are known.
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        ];

        $kids = [];
        for ($number = 1; $number <= $pages; $number++) {
            $pageId = count($objects) + 1;
            $contentId = $pageId + 1;
            $kids[] = "{$pageId} 0 R";

            $stream = self::pageContent($title, $number, $pages, $paragraphs);
            $objects[$pageId] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %d %d] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
                self::WIDTH, self::HEIGHT, $contentId,
            );
            $objects[$contentId] = '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream";
        }

        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.$pages.' >>';

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= 'xref'."\n".'0 '.(count($objects) + 1)."\n".'0000000000 65535 f '."\n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf('%010d 00000 n ', $offset)."\n";
        }

        return $pdf.'trailer'."\n".'<< /Size '.(count($objects) + 1).' /Root 1 0 R >>'."\n"
            .'startxref'."\n".$xref."\n".'%%EOF'."\n";
    }

    /**
     * @param  list<string>  $paragraphs
     */
    private static function pageContent(string $title, int $number, int $pages, array $paragraphs): string
    {
        $lines = [];
        $y = self::HEIGHT - 90;

        $lines[] = self::text('F2', 11, 60, $y, mb_strtoupper(self::clip($title, 60)));
        $y -= 50;
        $lines[] = self::text('F2', 26, 60, $y, $number === 1 ? 'Introduction' : 'Chapter '.($number - 1));
        $y -= 40;

        foreach ($paragraphs as $i => $paragraph) {
            foreach (explode("\n", wordwrap($paragraph, 82)) as $row) {
                $lines[] = self::text('F1', 12, 60, $y, $row);
                $y -= 19;
            }
            $y -= 14;
            if ($i === 0 && $number % 2 === 0) {
                // A grey box stands in for a figure on every other page.
                $lines[] = sprintf('0.93 g 60 %d 475 140 re f 0 g', $y - 140);
                $lines[] = self::text('F1', 10, 72, $y - 128, 'Figure '.$number.'. A diagram would appear here.');
                $y -= 166;
            }
        }

        $lines[] = self::text('F1', 10, (int) (self::WIDTH / 2) - 30, 40, "Page {$number} of {$pages}");

        return implode("\n", $lines);
    }

    private static function text(string $font, int $size, int $x, int $y, string $text): string
    {
        // The standard fonts use WinAnsi (Windows-1252) encoding, not UTF-8.
        $text = (string) iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        $text = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);

        return "BT /{$font} {$size} Tf {$x} {$y} Td ({$text}) Tj ET";
    }

    private static function clip(string $text, int $length): string
    {
        return mb_strlen($text) > $length ? rtrim(mb_substr($text, 0, $length - 3)).'...' : $text;
    }
}
