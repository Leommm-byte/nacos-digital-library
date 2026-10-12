<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BookStatus;
use App\Enums\ElectionStatus;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Book;
use App\Models\Election;
use App\Models\RollEntry;
use App\Models\User;
use App\Support\Operations\Alerts;
use App\Support\Operations\Backups;
use App\Support\Operations\Heartbeat;
use App\Support\Sessions\ProgrammeSessions;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $today = now()->timezone((string) config('app.display_timezone'))->startOfDay()->utc();

        return view('admin.dashboard', [
            'stats' => [
                'pending' => Book::query()->where('status', BookStatus::Pending)->count(),
                'approved' => Book::query()->approved()->count(),
                'users' => User::query()->where('status', UserStatus::Active)->count(),
                'suspended' => User::query()->where('status', UserStatus::Suspended)->count(),
                'uploadsToday' => Book::withTrashed()->where('created_at', '>=', $today)->count(),
                'roll' => RollEntry::query()->count(),
                'rollWithoutAccount' => RollEntry::query()->whereNotIn('matric_number', fn ($query) => $query->select('matric_number')->from('users'))->count(),
                'openElections' => Election::query()->where('status', ElectionStatus::Open)->count(),
            ],
            'session' => ProgrammeSessions::summary(),
            'recentActions' => AuditLog::query()->with('user:id,fullname,matric_number')->latest('id')->limit(8)->get(),
            'recentUploads' => Book::query()->with('uploader:id,fullname')->latest('id')->limit(5)->get(),
            'health' => $this->health(),
        ]);
    }

    /**
     * Is the cron running, and are backups happening? (docs/deployment.md)
     *
     * @return array{cron: Carbon|null, cronOk: bool, backup: Carbon|null, backupOk: bool, offsite: bool, files: Carbon|null, filesWaiting: int, failure: string|null, release: string}
     */
    private function health(): array
    {
        $status = Backups::status();
        $date = fn (string $key) => isset($status[$key]) && is_string($status[$key]) ? Carbon::parse($status[$key]) : null;
        $cron = Heartbeat::last();
        $backup = $date('database_at');
        $failed = $date('failed_at');

        return [
            'cron' => $cron,
            'cronOk' => $cron !== null && $cron->gte(now()->subMinutes((int) config('operations.scheduler_max_silence_minutes'))),
            'backup' => $backup,
            'backupOk' => $backup !== null && $backup->gte(now()->subHours((int) config('operations.backups.max_age_hours'))),
            'offsite' => Backups::offsite() !== null,
            'files' => $date('files_at'),
            'filesWaiting' => (int) ($status['files_waiting'] ?? 0),
            // Only a failure since the last good backup.
            'failure' => $failed !== null && ($backup === null || $failed->gt($backup)) ? (string) ($status['failure'] ?? '') : null,
            'release' => Alerts::release(),
        ];
    }
}
