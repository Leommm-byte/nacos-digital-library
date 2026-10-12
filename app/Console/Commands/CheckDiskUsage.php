<?php

namespace App\Console\Commands;

use App\Support\Operations\Alerts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Number;
use Throwable;

class CheckDiskUsage extends Command
{
    protected $signature = 'ops:disk';

    protected $description = 'Email a warning when uploads, backups and the database pass DISK_WARN_GB';

    public function handle(): int
    {
        $files = 0;
        foreach (File::allFiles(storage_path('app')) as $file) {
            $files += $file->getSize();
        }

        $database = $this->databaseBytes();
        $total = $files + $database;
        $limit = (float) config('operations.disk_warn_gb') * 1024 ** 3;

        $this->info('Files '.Number::fileSize($files).', database '.Number::fileSize($database).', limit '.Number::fileSize($limit).'.');

        if ($total > $limit) {
            Alerts::notice(
                'Disk space is running low',
                'Uploads and backups use '.Number::fileSize($files).' and the database '.Number::fileSize($database)
                .', together over the '.config('operations.disk_warn_gb')." GB warning level (DISK_WARN_GB).\n\n"
                ."Check the hosting plan's disk allowance in the DreamHost panel, and consider removing old or duplicate books.",
                24 * 60,
            );
        }

        return self::SUCCESS;
    }

    private function databaseBytes(): int
    {
        try {
            $driver = DB::connection()->getDriverName();

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                return (int) DB::table('information_schema.tables')
                    ->where('table_schema', DB::connection()->getDatabaseName())
                    ->sum(DB::raw('data_length + index_length'));
            }

            if ($driver === 'sqlite') {
                return (int) @filesize((string) config('database.connections.sqlite.database'));
            }
        } catch (Throwable) {
            // Unknown: counted as nothing rather than failing the check.
        }

        return 0;
    }
}
