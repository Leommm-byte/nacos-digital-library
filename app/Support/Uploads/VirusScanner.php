<?php

namespace App\Support\Uploads;

use Symfony\Component\Process\Process;

/**
 * Optional ClamAV check (config `uploads.clamav`). Shared hosting has no
 * ClamAV, so by default nothing is scanned; the other defences still
 * apply: PDFs are checked by content, images are re-encoded, and files are
 * only ever served from behind a login, inline, with `nosniff`, and shown
 * through PDF.js with scripting off.
 */
class VirusScanner
{
    public const CLEAN = 'clean';

    public const INFECTED = 'infected';

    public const FAILED = 'failed';

    public static function enabled(): bool
    {
        return (string) config('uploads.clamav') !== '';
    }

    /**
     * @return self::CLEAN|self::INFECTED|self::FAILED
     */
    public static function scan(string $path): string
    {
        if (! self::enabled()) {
            return self::CLEAN;
        }

        $process = new Process([(string) config('uploads.clamav'), '--no-summary', $path]);
        $process->setTimeout(60);
        $process->run();

        // ClamAV exits with 0 for clean, 1 for a virus, 2 for an error.
        return match ($process->getExitCode()) {
            0 => self::CLEAN,
            1 => self::INFECTED,
            default => self::FAILED,
        };
    }
}
