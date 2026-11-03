<?php

namespace App\Http\Controllers\Vote\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationActivity;
use App\Support\Departments\Activity;
use App\Support\Departments\ActivityActions;
use App\Support\MatricNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A department's activity log, like NACOS's audit log: who registered,
 * signed in, voted (never how) and changed the roll or an election, when
 * and from where. Filters by action, election, person and day (Lagos
 * time), and exports the filtered log as CSV. Only this department's
 * entries, for its admins and NACOS admins.
 */
class ActivityController extends Controller
{
    public const PER_PAGE = 25;

    public function index(Request $request, Organization $organization): View
    {
        $filters = $this->filters($request);

        $entries = $this->query($organization, $filters)
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $actions = $organization->activity()->distinct()->orderBy('action')->pluck('action')
            ->mapWithKeys(fn ($action) => [(string) $action => ActivityActions::label((string) $action)])
            ->sort()
            ->all();

        return view('vote.admin.activity', [
            'entries' => $entries,
            'filters' => $filters,
            'actions' => $actions,
            'elections' => $organization->elections()->latest('id')->pluck('title', 'id')->all(),
        ]);
    }

    /**
     * The filtered log as a CSV file, newest first, read in chunks so a
     * long log never has to fit in memory.
     */
    public function export(Request $request, Organization $organization): StreamedResponse
    {
        $filters = $this->filters($request);
        $zone = (string) config('app.display_timezone');
        $labels = ActivityActions::details();

        Activity::record($organization, 'activity_exported', null, array_filter($filters));

        $query = $this->query($organization, $filters);

        return response()->streamDownload(function () use ($query, $zone, $labels) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fwrite($out, "\u{FEFF}"); // Excel reads the names' accents correctly.
            fputcsv($out, ['Date', 'Time', 'Action', 'Action code', 'Name', 'Matric number or email', 'Done by', 'Details', 'IP address'], escape: '');

            $query->lazyByIdDesc(500, 'id')->each(function (OrganizationActivity $entry) use ($out, $zone, $labels) {
                $at = $entry->created_at->timezone($zone);
                $details = collect($entry->meta ?? [])
                    ->filter(fn ($value) => is_scalar($value))
                    ->map(fn ($value, $key) => ($labels[$key] ?? $key).': '.(is_bool($value) ? ($value ? 'yes' : 'no') : $value))
                    ->implode('; ');

                fputcsv($out, array_map($this->cell(...), [
                    $at->format('Y-m-d'),
                    $at->format('H:i:s'),
                    ActivityActions::label($entry->action),
                    $entry->action,
                    $entry->actor_name ?? '',
                    $entry->actor_login ?? '',
                    match ($entry->actor_type) {
                        'voter' => 'Department account',
                        'nacos' => 'NACOS admin',
                        default => $entry->action === 'login_failed' ? 'Unknown' : 'System',
                    },
                    $details,
                    $entry->ip_address ?? '',
                ]), escape: '');
            });

            fclose($out);
        }, 'activity-'.now()->timezone($zone)->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return array{action?: string|null, election?: string|null, who?: string|null, from?: string|null, to?: string|null}
     */
    private function filters(Request $request): array
    {
        /** @var array{action?: string|null, election?: string|null, who?: string|null, from?: string|null, to?: string|null} $filters */
        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:64'],
            'election' => ['nullable', 'integer'],
            'who' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return $filters;
    }

    /**
     * @param  array{action?: string|null, election?: string|null, who?: string|null, from?: string|null, to?: string|null}  $filters
     * @return Builder<OrganizationActivity>
     */
    private function query(Organization $organization, array $filters): Builder
    {
        $zone = (string) config('app.display_timezone');
        $who = trim((string) ($filters['who'] ?? ''));

        return OrganizationActivity::query()
            ->where('organization_id', $organization->id)
            ->when($filters['action'] ?? null, fn (Builder $query, $action) => $query->where('action', $action))
            ->when($filters['election'] ?? null, fn (Builder $query, $election) => $query->where('subject_type', 'Election')->where('subject_id', (int) $election))
            ->when($who !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('actor_name', 'like', '%'.$who.'%')
                ->orWhere('actor_login', 'like', '%'.(str_contains($who, '@') ? strtolower($who) : MatricNumber::normalize($who)).'%')))
            ->when($filters['from'] ?? null, fn (Builder $query, $from) => $query->where('created_at', '>=', Carbon::parse((string) $from, $zone)->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn (Builder $query, $to) => $query->where('created_at', '<=', Carbon::parse((string) $to, $zone)->endOfDay()->utc()));
    }

    /**
     * Spreadsheets run a cell starting with = + - @ as a formula; such
     * values (a name typed by anyone) are kept as plain text.
     */
    private function cell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
