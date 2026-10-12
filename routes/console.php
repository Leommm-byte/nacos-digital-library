<?php

use App\Support\Operations\Heartbeat;
use Illuminate\Support\Facades\Schedule;

/*
| Shared hosting has no long-running queue worker. Instead the cron runs
| `schedule:run` every minute, and this drains whatever jobs are waiting,
| stopping before the next minute's run starts.
*/
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('queue:prune-failed --hours=168')->daily();

// Elections close at their end time even if nobody opens the page.
Schedule::command('elections:close')->everyMinute()->withoutOverlapping();

// Account invitations go out a few at a time, under the host's hourly limit.
Schedule::command('invitations:send')->everyMinute()->withoutOverlapping();

// Backups: the database every night, uploaded files off-site every hour
// (only new ones, a little at a time). See docs/deployment.md.
Schedule::command('backup:run --database')->dailyAt('02:30')->timezone('Africa/Lagos')->withoutOverlapping(60);
Schedule::command('backup:run --files')->hourlyAt(20)->withoutOverlapping(60);
Schedule::command('ops:disk')->dailyAt('07:10')->timezone('Africa/Lagos');

// Proves the cron is running: /up/scheduler checks how long ago this was.
Schedule::call(fn () => Heartbeat::beat())->everyMinute()->name('heartbeat');
