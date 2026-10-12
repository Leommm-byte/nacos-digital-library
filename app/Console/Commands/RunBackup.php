<?php

namespace App\Console\Commands;

use App\Support\Operations\Alerts;
use App\Support\Operations\Backups;
use Illuminate\Console\Command;
use Illuminate\Support\Number;
use Throwable;

class RunBackup extends Command
{
    protected $signature = 'backup:run {--database : Only dump the database} {--files : Only copy uploaded files off-site}';

    protected $description = 'Dump the database and copy uploaded files off-site (see docs/deployment.md)';

    public function handle(Backups $backups): int
    {
        $all = ! $this->option('database') && ! $this->option('files');

        try {
            if ($all || $this->option('database')) {
                $name = $backups->database();
                $this->info("Database saved as {$name}".(Backups::offsite() ? ' and copied off-site.' : ' (no off-site storage set up).'));
            }

            if ($all || $this->option('files')) {
                if (Backups::offsite() === null) {
                    $this->warn('No off-site storage set up (BACKUP_DISK), so files were not copied.');
                } else {
                    $files = $backups->files();
                    $this->info("Copied {$files['copied']} ".str('file')->plural($files['copied']).' ('.Number::fileSize($files['bytes']).') off-site'.($files['waiting'] ? ", {$files['waiting']} left for the next run." : '.'));
                }
            }
        } catch (Throwable $e) {
            Backups::record(['failed_at' => now()->toIso8601String(), 'failure' => $e->getMessage()]);
            Alerts::notice('Backup failed', "The backup didn't finish:\n\n".$e->getMessage()."\n\nIt runs again on schedule. If it keeps failing, see \"Backups\" in docs/deployment.md.", 60);
            report($e);
            $this->error('Backup failed: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
