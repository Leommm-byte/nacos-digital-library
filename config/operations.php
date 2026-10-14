<?php

/*
| Running the live site: error emails, backups, health checks and the disk
| warning. See docs/deployment.md.
*/
return [

    // Who is emailed when something breaks (errors, failed backups, disk).
    'alert_email' => env('ALERT_EMAIL'),

    'alerts' => [
        // The same error is emailed at most once in this many minutes…
        'same_error_minutes' => 60,
        // …and no more than this many error emails go out in an hour.
        'per_hour' => 10,
    ],

    'backups' => [
        // The off-site copy (config/filesystems.php "backups", any
        // S3-compatible storage such as Backblaze B2). Empty: local only.
        'disk' => env('BACKUP_DISK'),
        // Database dumps kept on the server.
        'keep_local' => 3,
        // Off-site: the last dump of each of the last N days, weeks, months.
        'keep_daily' => 7,
        'keep_weekly' => 4,
        'keep_monthly' => 6,
        // Uploaded files are copied off-site a little at a time (only new
        // or changed ones), this much per run, so a run stays short.
        'files_per_run_mb' => (int) env('BACKUP_FILES_PER_RUN_MB', 300),
        // /up/backups reports a problem when the last dump is older.
        'max_age_hours' => 26,
        // DreamHost has it on the PATH; set the full path if not.
        'mysqldump' => env('MYSQLDUMP_PATH', 'mysqldump'),
    ],

    // /up/scheduler reports a problem when the cron hasn't run for this long.
    'scheduler_max_silence_minutes' => 5,

    // A daily email when the files and database together pass this size.
    'disk_warn_gb' => (float) env('DISK_WARN_GB', 20),

];
