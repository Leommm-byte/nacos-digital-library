<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
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
        $zone = (string) config('app.display_timezone');
        $data = $request->validate([
            'kind' => ['nullable', Rule::in(array_keys(SharedDevices::KINDS))],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'min' => ['nullable', 'integer', 'min:2', 'max:50'],
        ]);

        $kind = (string) ($data['kind'] ?? 'device');
        $from = isset($data['from']) ? Carbon::parse((string) $data['from'], $zone)->startOfDay() : now($zone)->subDays(30)->startOfDay();
        $to = isset($data['to']) ? Carbon::parse((string) $data['to'], $zone)->endOfDay() : now($zone)->endOfDay();
        $min = (int) ($data['min'] ?? ($kind === 'device' ? 2 : 3));

        return view('admin.vote-holds.devices', [
            'kind' => $kind,
            'from' => $from,
            'to' => $to,
            'min' => $min,
            'groups' => SharedDevices::groups($kind, $from->copy()->utc(), $to->copy()->utc(), $min),
        ]);
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
            'users' => ['required', 'array', 'max:500'],
            'users.*' => ['integer'],
            'reason' => ['required', 'string', 'max:255'],
        ], [
            'users.required' => 'Tick the accounts to put on hold.',
            'reason.required' => 'Say why, for the record (for example "Registered from the same phone as 6 others").',
        ]);

        $held = User::query()->whereIn('id', $data['users'])->get()
            ->filter(fn (User $user) => Gate::allows('manage', $user))
            ->filter(fn (User $user) => VoteHolds::hold($user, trim((string) $data['reason'])))
            ->count();

        VoteHolds::recount();

        return back()->with('status', $held === 1 ? '1 account is on hold.' : "{$held} accounts are on hold.");
    }
}
