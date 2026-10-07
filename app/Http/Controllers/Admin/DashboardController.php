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
use App\Support\Settings;
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
            'session' => Settings::get('academic_session'),
            'recentActions' => AuditLog::query()->with('user:id,fullname,matric_number')->latest('id')->limit(8)->get(),
            'recentUploads' => Book::query()->with('uploader:id,fullname')->latest('id')->limit(5)->get(),
        ]);
    }
}
