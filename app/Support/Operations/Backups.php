<?php

namespace App\Support\Operations;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FileAttributes;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Nightly database dumps and an off-site copy of everything uploaded.
 *
 * - The database is dumped and gzipped to storage/app/backups (the last
 *   few kept there) and copied off-site, where the last dump of each of the
 *   last 7 days, 4 weeks and 6 months is kept.
 * - Uploaded files (storage/app/private: books, covers, photos) are copied
 *   off-site a little at a time: only new or changed files, up to a size
 *   per run, so the first copy of a big library spreads over a few runs.
 *   Files deleted on the site are kept off-site, in case it was a mistake.
 *
 * What happened last is kept in storage/app/backups/status.json, read by
 * /up/backups and the admin dashboard.
 */
class Backups
{
    public function __construct(private DatabaseDump $dump) {}

    public static function directory(): string
    {
        return storage_path('app/backups');
    }

    /**
     * The off-site storage, or null when none is set up.
     */
    public static function offsite(): ?Filesystem
    {
        $disk = config('operations.backups.disk');

        return is_string($disk) && $disk !== '' ? Storage::disk($disk) : null;
    }

    /**
     * Dumps the database; returns the file's name.
     */
    public function database(): string
    {
        File::ensureDirectoryExists(self::directory(), 0700);
        $name = 'db-'.now('UTC')->format('Ymd-His').'.sql.gz';
        $path = self::directory().'/'.$name;

        $this->dump->write($path);

        $offsite = self::offsite();
        if ($offsite !== null) {
            $stream = fopen($path, 'rb');
            $offsite->writeStream("db/{$name}", $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
            $offsite->delete(self::expired($offsite->files('db')));
        }

        // Only the newest few stay on the server.
        $local = collect(File::glob(self::directory().'/db-*.sql.gz'))->sort()->values();
        File::delete($local->slice(0, max(0, $local->count() - (int) config('operations.backups.keep_local')))->all());

        self::record([
            'database_at' => now()->toIso8601String(),
            'database_file' => $name,
            'database_bytes' => (int) filesize($path),
            'database_offsite' => $offsite !== null,
        ]);

        return $name;
    }

    /**
     * Copies new or changed uploads off-site, up to the size allowed per
     * run.
     *
     * @return array{copied: int, bytes: int, waiting: int}
     */
    public function files(): array
    {
        $offsite = self::offsite();
        if ($offsite === null) {
            return ['copied' => 0, 'bytes' => 0, 'waiting' => 0];
        }

        $root = storage_path('app/private');
        $budget = (int) config('operations.backups.files_per_run_mb') * 1024 * 1024;

        // What's already there: one listing instead of a check per file.
        $copied = [];
        foreach ($offsite->listContents('files', true) as $item) {
            if ($item->isFile()) {
                $copied[substr($item->path(), strlen('files/'))] = $item instanceof FileAttributes ? $item->fileSize() : null;
            }
        }

        $done = 0;
        $bytes = 0;
        $waiting = 0;

        /** @var SplFileInfo $file */
        foreach (File::exists($root) ? File::allFiles($root) : [] as $file) {
            $relative = str_replace('\\', '/', $file->getRelativePathname());
            $size = $file->getSize();

            if (array_key_exists($relative, $copied) && $copied[$relative] === $size) {
                continue;
            }

            // Always at least one file per run, however big.
            if ($done > 0 && $bytes + $size > $budget) {
                $waiting++;

                continue;
            }

            $stream = fopen($file->getPathname(), 'rb');
            $offsite->writeStream("files/{$relative}", $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
            $done++;
            $bytes += $size;
        }

        self::record([
            'files_at' => now()->toIso8601String(),
            'files_copied' => $done,
            'files_waiting' => $waiting,
        ]);

        return ['copied' => $done, 'bytes' => $bytes, 'waiting' => $waiting];
    }

    /**
     * The off-site dumps to delete: all but the newest of each of the last
     * few days, weeks and months.
     *
     * @param  array<int, string>  $paths  "db/db-20261012-013000.sql.gz"
     * @return list<string>
     */
    public static function expired(array $paths, ?Carbon $now = null): array
    {
        $now ??= now('UTC');
        $dated = [];

        foreach ($paths as $path) {
            if (preg_match('/db-(\d{8}-\d{6})\.sql\.gz$/', $path, $match)) {
                $dated[$path] = Carbon::createFromFormat('Ymd-His', $match[1], 'UTC');
            }
        }

        // Newest first, so the first seen in each day, week or month is kept.
        arsort($dated);
        $keep = [];

        foreach ([
            ['keep_daily', 'Y-m-d', fn (Carbon $at) => $at->greaterThan($now->copy()->subDays((int) config('operations.backups.keep_daily')))],
            ['keep_weekly', 'o-W', fn (Carbon $at) => $at->greaterThan($now->copy()->subWeeks((int) config('operations.backups.keep_weekly')))],
            ['keep_monthly', 'Y-m', fn (Carbon $at) => $at->greaterThan($now->copy()->subMonths((int) config('operations.backups.keep_monthly')))],
        ] as [, $format, $recent]) {
            $seen = [];
            foreach ($dated as $path => $at) {
                if ($at !== null && $recent($at) && ! isset($seen[$at->format($format)])) {
                    $seen[$at->format($format)] = true;
                    $keep[$path] = true;
                }
            }
        }

        return array_values(array_filter(array_keys($dated), fn (string $path) => ! isset($keep[$path])));
    }

    /**
     * @return array<string, mixed>
     */
    public static function status(): array
    {
        $file = self::directory().'/status.json';
        $status = File::exists($file) ? json_decode((string) File::get($file), true) : null;

        return is_array($status) ? $status : [];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function record(array $values): void
    {
        File::ensureDirectoryExists(self::directory(), 0700);
        File::put(self::directory().'/status.json', (string) json_encode([...self::status(), ...$values], JSON_PRETTY_PRINT));
    }
}
