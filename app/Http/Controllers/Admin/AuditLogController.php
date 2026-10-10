<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\Audit;
use App\Support\AuditActions;
use App\Support\MatricNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The audit log: who did what, when and from where. Filters by action,
 * person and day (Lagos time), a page at a time grouped by day, and exports
 * the filtered log as CSV.
 */
class AuditLogController extends Controller
{
    public const PER_PAGE = 25;

    public function index(Request $request): View
    {
        $filters = $this->filters($request);

        $entries = $this->query($filters)
            ->with('user:id,fullname,matric_number')
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $actions = AuditLog::query()->distinct()->orderBy('action')->pluck('action')
            ->mapWithKeys(fn ($action) => [(string) $action => AuditActions::label((string) $action)])
            ->all();

        return view('admin.audit', [
            'entries' => $entries,
            'filters' => $filters,
            'actions' => $actions,
        ]);
    }

    /**
     * The filtered log as a CSV file, newest first, read in chunks so a
     * long log never has to fit in memory.
     */
    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $zone = (string) config('app.display_timezone');

        Audit::record('audit_exported', null, array_filter([
            'action' => $filters['action'] ?? null,
            'who' => $filters['who'] ?? null,
            'from' => $filters['from'] ?? null,
            'to' => $filters['to'] ?? null,
        ]));

        $query = $this->query($filters)->with('user:id,fullname,matric_number');

        return response()->streamDownload(function () use ($query, $zone) {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            // Lets Excel read the names' accents correctly.
            fwrite($out, "\u{FEFF}");
            fputcsv($out, ['Date', 'Time', 'Action', 'Action code', 'Name', 'Matric number', 'Subject', 'Details', 'IP address'], escape: '');

            $query->lazyByIdDesc(500, 'id')->each(function (AuditLog $entry) use ($out, $zone) {
                $at = $entry->created_at->timezone($zone);
                $details = collect($entry->meta ?? [])
                    ->filter(fn ($value) => is_scalar($value))
                    ->map(fn ($value, $key) => $key.': '.(is_bool($value) ? ($value ? 'yes' : 'no') : $value))
                    ->implode('; ');

                fputcsv($out, array_map($this->cell(...), [
                    $at->format('Y-m-d'),
                    $at->format('H:i:s'),
                    AuditActions::label($entry->action),
                    $entry->action,
                    $entry->user?->fullname ?? 'System',
                    $entry->user?->matric_number ?? '',
                    $entry->subject_type ? class_basename($entry->subject_type).' #'.$entry->subject_id : '',
                    $details,
                    $entry->ip_address ?? '',
                ]), escape: '');
            });

            fclose($out);
        }, 'audit-log-'.now()->timezone($zone)->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return array{action?: string|null, who?: string|null, from?: string|null, to?: string|null}
     */
    private function filters(Request $request): array
    {
        /** @var array{action?: string|null, who?: string|null, from?: string|null, to?: string|null} $filters */
        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:64'],
            'who' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return $filters;
    }

    /**
     * @param  array{action?: string|null, who?: string|null, from?: string|null, to?: string|null}  $filters
     * @return Builder<AuditLog>
     */
    private function query(array $filters): Builder
    {
        $zone = (string) config('app.display_timezone');
        $who = trim((string) ($filters['who'] ?? ''));

        return AuditLog::query()
            ->when($filters['action'] ?? null, fn (Builder $query, $action) => $query->where('action', $action))
            ->when($who !== '', fn (Builder $query) => $query->whereHas('user', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('fullname', 'like', '%'.$who.'%')
                ->orWhere('matric_number', 'like', '%'.MatricNumber::normalize($who).'%'))))
            ->when($filters['from'] ?? null, fn (Builder $query, $from) => $query->where('created_at', '>=', Carbon::parse((string) $from, $zone)->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn (Builder $query, $to) => $query->where('created_at', '<=', Carbon::parse((string) $to, $zone)->endOfDay()->utc()));
    }

    /**
     * Spreadsheets run a cell starting with = + - @ as a formula; such
     * values (a name or comment typed by anyone) are kept as plain text.
     */
    private function cell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
