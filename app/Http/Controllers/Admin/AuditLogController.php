<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\AuditActions;
use App\Support\MatricNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * The audit log: who did what, when and from where. Filters by action,
 * person and day (Lagos time).
 */
class AuditLogController extends Controller
{
    public function __invoke(Request $request): View
    {
        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:64'],
            'who' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $zone = (string) config('app.display_timezone');
        $who = trim((string) ($filters['who'] ?? ''));

        $entries = AuditLog::query()
            ->with('user:id,fullname,matric_number')
            ->when($filters['action'] ?? null, fn (Builder $query, $action) => $query->where('action', $action))
            ->when($who !== '', fn (Builder $query) => $query->whereHas('user', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('fullname', 'like', '%'.$who.'%')
                ->orWhere('matric_number', 'like', '%'.MatricNumber::normalize($who).'%'))))
            ->when($filters['from'] ?? null, fn (Builder $query, $from) => $query->where('created_at', '>=', Carbon::parse((string) $from, $zone)->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn (Builder $query, $to) => $query->where('created_at', '<=', Carbon::parse((string) $to, $zone)->endOfDay()->utc()))
            ->latest('id')
            ->paginate(50)
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
}
