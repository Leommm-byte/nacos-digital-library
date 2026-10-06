<?php

namespace App\Support\Uploads;

use GdImage;

/**
 * Re-encodes uploaded images with GD: scanned pages and covers.
 *
 * Re-encoding turns any JPEG, PNG or WebP into a plain JPEG, applies the
 * camera's rotation, drops metadata (such as a phone's GPS location) and
 * leaves nothing of the original file's bytes behind.
 */
class Images
{
    /**
     * @return array{data: string, width: int, height: int}
     */
    public static function toJpeg(string $bytes, int $maxSide, int $quality = 80): array
    {
        $image = @imagecreatefromstring($bytes);

        if (! $image instanceof GdImage) {
            throw new UnreadableImage('Not a readable image.');
        }

        $image = self::orient($image, $bytes);
        $image = self::fit($image, $maxSide);

        // Transparent areas (PNG, WebP) become white rather than black.
        $canvas = imagecreatetruecolor(imagesx($image), imagesy($image));
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
        imageinterlace($canvas, true);

        ob_start();
        imagejpeg($canvas, null, $quality);
        $data = (string) ob_get_clean();

        return ['data' => $data, 'width' => imagesx($canvas), 'height' => imagesy($canvas)];
    }

    private static function fit(GdImage $image, int $maxSide): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = $maxSide / max($width, $height);

        if ($scale >= 1) {
            return $image;
        }

        $scaled = imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)), IMG_BICUBIC);

        return $scaled instanceof GdImage ? $scaled : $image;
    }

    /**
     * Phones store photos sideways with an EXIF note saying how to turn them.
     */
    private static function orient(GdImage $image, string $bytes): GdImage
    {
        if (! function_exists('exif_read_data') || ! str_starts_with($bytes, "\xFF\xD8")) {
            return $image;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes));
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
        $angle = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);

        return $rotated instanceof GdImage ? $rotated : $image;
    }
}
