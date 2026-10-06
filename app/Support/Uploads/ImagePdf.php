<?php

namespace App\Support\Uploads;

/**
 * Builds a PDF with one JPEG per page. The JPEG bytes go into the PDF
 * unchanged (DCTDecode), so no PDF library is needed and quality is kept.
 */
class ImagePdf
{
    /** Pixels per inch assumed for scanned pages (sets the page size). */
    private const DPI = 150;

    /**
     * @param  list<array{data: string, width: int, height: int}>  $pages
     */
    public static function make(array $pages): string
    {
        $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 2 => ''];
        $kids = [];

        foreach ($pages as $i => $page) {
            $imageId = count($objects) + 1;
            $contentId = $imageId + 1;
            $pageId = $imageId + 2;
            $kids[] = "{$pageId} 0 R";

            $width = round($page['width'] * 72 / self::DPI, 2);
            $height = round($page['height'] * 72 / self::DPI, 2);
            $draw = "q {$width} 0 0 {$height} 0 0 cm /Im{$i} Do Q";

            $objects[$imageId] = sprintf(
                "<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>\nstream\n%s\nendstream",
                $page['width'], $page['height'], strlen($page['data']), $page['data'],
            );
            $objects[$contentId] = '<< /Length '.strlen($draw)." >>\nstream\n{$draw}\nendstream";
            $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$width} {$height}] "
                ."/Resources << /XObject << /Im{$i} {$imageId} 0 R >> >> /Contents {$contentId} 0 R >>";
        }

        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($pages).' >>';

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf('%010d 00000 n ', $offset)."\n";
        }

        return $pdf."trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}
