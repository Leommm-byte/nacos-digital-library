<?php

namespace App\Support\Operations;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/**
 * The scheduler touches a file every minute; /up/scheduler reads how long
 * ago that was, so an uptime monitor notices when the cron stops (and with
 * it the queue, emails, backups and elections closing on time).
 */
class Heartbeat
{
    public static function path(): string
    {
        return storage_path('framework/heartbeat');
    }

    public static function beat(): void
    {
        File::put(self::path(), (string) now()->getTimestamp());
    }

    public static function last(): ?Carbon
    {
        $at = File::exists(self::path()) ? (int) File::get(self::path()) : 0;

        return $at > 0 ? Carbon::createFromTimestamp($at) : null;
    }
}
