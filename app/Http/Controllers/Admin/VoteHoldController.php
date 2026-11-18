<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use App\Support\Elections\SharedDevices;
use App\Support\Elections\VoteHolds;
use App\Support\MatricNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Accounts registered in someone else's name (docs/vote-integrity.md):
 * Shared devices finds accounts one phone acted for; suspicious ones are
 * put on hold, so they can't vote until an admin has seen the student in
 * person and released them (during an election too).
 */
class VoteHoldController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim($request->string('q')->toString());

        $held = User::query()
            ->whereNotNull('vote_held_at')
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('fullname', 'like', '%'.$search.'%')
                ->orWhere('matric_number', 'like', '%'.MatricNumber::normalize($search).'%')))
            ->with('voteHeldBy:id,fullname')
            ->latest('vote_held_at')
            ->paginate(25)
            ->withQueryString();

        return view('admin.vote-holds.index', [
            'held' => $held,
            'search' => $search,
            'total' => User::query()->whereNotNull('vote_held_at')->count(),
        ]);
    }

    public function devices(Request $request): View
    {
        $report = $this->report($request);

        return view('admin.vote-holds.devices', [
            ...$report,
            'groups' => SharedDevices::groups($report['kind'], $report['from']->copy()->utc(), $report['to']->copy()->utc(), $report['min']),
        ]);
    }

    /**
     * Shared devices as a spreadsheet (CSV), one row per account, to show
     * the committee or keep with the election's records.
     */
    public function export(Request $request): StreamedResponse
    {
        $report = $this->report($request);
        $zone = (string) config('app.display_timezone');
        $groups = SharedDevices::groups($report['kind'], $report['from']->copy()->utc(), $report['to']->copy()->utc(), $report['min'], 500);

        Audit::record('shared_devices_exported', null, [
            'kind' => $report['kind'],
            'from' => $report['from']->format('Y-m-d'),
            'to' => $report['to']->format('Y-m-d'),
            'count' => $groups->count(),
        ]);

        $time = fn ($at) => $at === null ? '' : Carbon::parse($at)->timezone($zone)->format('Y-m-d H:i');
        $cell = fn (?string $value) => preg_match('/^[=+\-@\t\r]/', (string) $value) === 1 ? "'".$value : (string) $value;

        return response()->streamDownload(function () use ($groups, $time, $cell, $report) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fwrite($out, "\u{FEFF}"); // Excel reads the names' accents correctly.
            fputcsv($out, [($report['kind'] === 'device' ? 'Same device' : 'Same network and browser').', '.$report['from']->format('j M Y').' to '.$report['to']->format('j M Y')], escape: '');
            fputcsv($out, ['Group', 'Address', 'Browser', 'Browser details as sent', 'Accounts in group', 'Signed up in group', 'Voted in group', 'Name', 'Matric number', 'Signed up here', 'Voted here', 'Sign-ins here', 'On hold'], escape: '');

            foreach ($groups as $index => $group) {
                foreach ($group['accounts'] as $account) {
                    fputcsv($out, array_map($cell, [
                        (string) ($index + 1),
                        (string) $group['ip'],
                        SharedDevices::browser($group['agent']),
                        $group['agent'],
                        (string) $group['accounts']->count(),
                        (string) $group['registered'],
                        (string) $group['voted'],
                        $account['user']->fullname,
                        $account['user']->matric_number,
                        $time($account['registered_at']),
                        $time($account['voted_at']),
                        (string) $account['logins'],
                        $account['user']->vote_held_at !== null ? 'Yes' : 'No',
                    ]), escape: '');
                }
            }

            fclose($out);
        }, 'shared-devices-'.$report['kind'].'-'.now()->timezone($zone)->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return array{kind: string, from: Carbon, to: Carbon, min: int}
     */
    private function report(Request $request): array
    {
        $zone = (string) config('app.display_timezone');
        $data = $request->validate([
            'kind' => ['nullable', Rule::in(array_keys(SharedDevices::KINDS))],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'min' => ['nullable', 'integer', 'min:2', 'max:50'],
        ]);

        $kind = (string) ($data['kind'] ?? 'device');

        return [
            'kind' => $kind,
            'from' => isset($data['from']) ? Carbon::parse((string) $data['from'], $zone)->startOfDay() : now($zone)->subDays(30)->startOfDay(),
            'to' => isset($data['to']) ? Carbon::parse((string) $data['to'], $zone)->endOfDay() : now($zone)->endOfDay(),
            'min' => (int) ($data['min'] ?? ($kind === 'device' ? 2 : 3)),
        ];
    }

    public function store(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('manage', $user);

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], ['reason.required' => 'Say why, for the record (for example "Registered from the same phone as 6 others").']);

        VoteHolds::hold($user, trim((string) $data['reason']));
        VoteHolds::recount();

        return back()->with('status', "{$user->firstName()} is on hold and can't vote until released.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('manage', $user);

        $data = $request->validate(['note' => ['required', 'string', 'max:255']], ['note.required' => 'Say how you checked it was them (for example "Saw student ID in person").']);

        VoteHolds::release($user, trim((string) $data['note']));
        VoteHolds::recount();

        return back()->with('status', "{$user->firstName()} is released and can vote.");
    }

    /**
     * Several accounts at once, from Shared devices.
     */
    public function storeMany(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'users' => ['required_without:group', 'array', 'max:2000'],
            'users.*' => ['integer'],
            // A whole group from Shared devices, worked out again here.
            'group' => ['nullable', 'string', 'max:64'],
            'reason' => ['required', 'string', 'max:255'],
        ], [
            'users.required_without' => 'Tick the accounts to put on hold.',
            'reason.required' => 'Say why, for the record (for example "Registered from the same phone as 6 others").',
        ]);

        $ids = $data['users'] ?? [];

        if (isset($data['group'])) {
            $report = $this->report($request);
            $group = SharedDevices::groups($report['kind'], $report['from']->copy()->utc(), $report['to']->copy()->utc(), 2, 500)
                ->firstWhere('key', (string) $data['group']);
            $ids = $group === null ? [] : $group['accounts']->map(fn (array $account) => $account['user']->id)->all();
        }

        $held = User::query()->whereIn('id', $ids)->get()
            ->filter(fn (User $user) => Gate::allows('manage', $user))
            ->filter(fn (User $user) => VoteHolds::hold($user, trim((string) $data['reason'])))
            ->count();

        VoteHolds::recount();

        return back()->with('status', $held === 1 ? '1 account is on hold.' : "{$held} accounts are on hold.");
    }
}
