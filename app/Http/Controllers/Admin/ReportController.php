<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BookStatus;
use App\Enums\Level;
use App\Enums\Programme;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Library and account numbers. Months are Lagos months and carry their
 * year (the legacy report merged the same month of different years), and
 * departments come from the database, not a hard-coded list.
 */
class ReportController extends Controller
{
    public function __invoke(): View
    {
        $zone = (string) config('app.display_timezone');
        $start = now()->timezone($zone)->startOfMonth()->subMonths(11);

        return view('admin.reports', [
            'approvedByMonth' => $this->byMonth(
                Book::query()->where('status', BookStatus::Approved)->where('approved_at', '>=', $start->copy()->utc())->pluck('approved_at')->all(),
                $start,
            ),
            'uploadsByMonth' => $this->byMonth(
                Book::withTrashed()->where('created_at', '>=', $start->copy()->utc())->pluck('created_at')->all(),
                $start,
            ),
            'byDepartment' => Department::query()->orderBy('name')->get(['id', 'name'])
                ->mapWithKeys(fn (Department $department) => [$department->name => Book::query()->approved()->where('department_id', $department->id)->count()])
                ->all(),
            'byLevel' => collect(Level::cases())
                ->mapWithKeys(fn (Level $level) => [$level->label() => Book::query()->approved()->where('level', $level)->count()])
                ->all(),
            'accounts' => collect(Programme::cases())->mapWithKeys(fn (Programme $programme) => [
                $programme->label() => collect(Level::cases())->mapWithKeys(fn (Level $level) => [
                    $level->label() => User::query()->where('status', UserStatus::Active)->where('programme', $programme)->where('level', $level)->count(),
                ])->all(),
            ])->all(),
        ]);
    }

    /**
     * Counts per month for the last 12 months, oldest first.
     *
     * @param  array<int, mixed>  $dates
     * @return array<string, int> "Oct 2026" => count
     */
    private function byMonth(array $dates, Carbon $start): array
    {
        $zone = (string) config('app.display_timezone');
        $months = [];

        for ($month = $start->copy(); count($months) < 12; $month->addMonth()) {
            $months[$month->format('M Y')] = 0;
        }

        foreach ($dates as $date) {
            if ($date === null) {
                continue;
            }

            $key = Carbon::parse($date)->timezone($zone)->format('M Y');
            if (isset($months[$key])) {
                $months[$key]++;
            }
        }

        return $months;
    }
}
