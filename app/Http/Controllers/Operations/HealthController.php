<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Support\Operations\Backups;
use App\Support\Operations\Heartbeat;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * For an uptime monitor (docs/deployment.md): plain "OK" with 200, or what's
 * wrong with 503, so the monitor's alert says which. /up (Laravel's) checks
 * the site itself answers.
 */
class HealthController extends Controller
{
    public function scheduler(): Response
    {
        $last = Heartbeat::last();
        $limit = (int) config('operations.scheduler_max_silence_minutes');

        if ($last === null || $last->lt(now()->subMinutes($limit))) {
            return $this->problem('The cron has not run '.($last ? 'since '.$last->toIso8601String() : 'yet').'.');
        }

        return $this->ok();
    }

    public function backups(): Response
    {
        $status = Backups::status();
        $at = isset($status['database_at']) && is_string($status['database_at']) ? Carbon::parse($status['database_at']) : null;
        $limit = (int) config('operations.backups.max_age_hours');

        if ($at === null || $at->lt(now()->subHours($limit))) {
            return $this->problem('No database backup in the last '.$limit.' hours.');
        }

        if (Backups::offsite() === null || ($status['database_offsite'] ?? false) !== true) {
            return $this->problem('The last database backup was not copied off-site.');
        }

        return $this->ok();
    }

    private function ok(): Response
    {
        return response('OK', 200, ['Content-Type' => 'text/plain', 'Cache-Control' => 'no-store']);
    }

    private function problem(string $message): Response
    {
        return response($message, 503, ['Content-Type' => 'text/plain', 'Cache-Control' => 'no-store']);
    }
}
