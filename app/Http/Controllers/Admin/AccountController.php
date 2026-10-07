<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Level;
use App\Enums\Programme;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\RollEntry;
use App\Models\User;
use App\Support\Audit;
use App\Support\TemporaryPassword;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Creates accounts for students on the nominal roll who don't have one,
 * class by class, with a random first password printed on a slip (the
 * legacy app used the surname). They choose their own at first login.
 *
 * Batches of 100, so a request stays well inside shared hosting's time
 * limit; the page offers the next batch.
 */
class AccountController extends Controller
{
    public const BATCH = 100;

    public function index(): View
    {
        $classes = DB::table('nominal_roll as r')
            ->leftJoin('users as u', 'u.matric_number', '=', 'r.matric_number')
            ->whereNotNull('r.programme')
            ->whereNotNull('r.level')
            ->selectRaw('r.programme, r.level, count(*) as total, sum(case when u.id is null then 1 else 0 end) as missing, sum(case when u.id is null and r.fullname is null then 1 else 0 end) as no_name')
            ->groupBy('r.programme', 'r.level')
            ->get()
            ->map(fn ($row) => [
                'key' => $row->programme.'|'.$row->level,
                'label' => (Level::tryFrom((string) $row->level)?->label() ?? $row->level).' '.(Programme::tryFrom((string) $row->programme)?->label() ?? $row->programme),
                'total' => (int) $row->total,
                'missing' => (int) $row->missing,
                'noName' => (int) $row->no_name,
            ])
            ->sortBy('label')
            ->values();

        return view('admin.accounts.index', [
            'classes' => $classes,
            'departments' => Department::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'classes' => ['required', 'array', 'min:1'],
            'classes.*' => ['string', 'regex:/^[a-z_]+\|[A-Z0-9]+$/'],
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')->where('is_active', true)],
        ], [
            'classes.required' => 'Tick at least one class.',
        ]);

        /** @var list<string> $classes */
        $classes = $data['classes'];
        $pending = $this->waiting($classes);
        $batch = (clone $pending)->orderBy('matric_number')->limit(self::BATCH)->get();

        if ($batch->isEmpty()) {
            return back()->withErrors(['classes' => 'Everyone named on the roll in these classes already has an account.']);
        }

        // Cheaper hashing for first passwords (changed at first login), so
        // a batch stays quick; never above the configured cost.
        $rounds = min(10, (int) config('hashing.bcrypt.rounds', 12));
        $takenEmails = User::query()->whereIn('email', $batch->pluck('email')->filter()->all())->pluck('email')->flip();
        $slips = [];

        DB::transaction(function () use ($batch, $data, $rounds, $takenEmails, &$slips) {
            foreach ($batch as $entry) {
                $password = TemporaryPassword::make();
                // An address already used (by an account, or twice on the
                // roll) is left off; they can add theirs later.
                $email = $entry->email !== null && ! $takenEmails->has($entry->email) ? $entry->email : null;
                if ($email !== null) {
                    $takenEmails->put($email, true);
                }

                $user = new User([
                    'fullname' => (string) $entry->fullname,
                    'matric_number' => $entry->matric_number,
                    'email' => $email,
                    'department_id' => (int) $data['department_id'],
                    'level' => $entry->level ?? Level::ND1,
                    'programme' => $entry->programme ?? Programme::FullTime,
                    'password' => Hash::make($password, ['rounds' => $rounds]),
                ]);
                $user->must_change_password = true;
                $user->save();

                $slips[] = [
                    'name' => $user->fullname,
                    'matric' => $user->matric_number,
                    'password' => $password,
                    'class' => $user->level->label().' '.$user->programme->label(),
                ];
            }
        });

        Audit::record('accounts_created', null, ['count' => count($slips), 'classes' => $classes, 'department_id' => (int) $data['department_id']]);

        return redirect()->route('admin.accounts.slips')->with('slips', [
            'slips' => $slips,
            'remaining' => $this->waiting($classes)->count(),
            'classes' => $classes,
            'department_id' => (int) $data['department_id'],
        ]);
    }

    /**
     * The slips of the accounts just created. Shown once: passwords are
     * stored hashed and can't be shown again.
     */
    public function slips(Request $request): View|RedirectResponse
    {
        $batch = $request->session()->get('slips');

        if (! is_array($batch)) {
            return redirect()->route('admin.accounts.index')
                ->with('status', 'Slips are only shown right after creating the accounts. Give someone a new password from their page in Users.');
        }

        return view('admin.accounts.slips', ['batch' => $batch]);
    }

    /**
     * Roll entries in these classes, with a name, that have no account.
     *
     * @param  list<string>  $classes
     * @return Builder<RollEntry>
     */
    private function waiting(array $classes): Builder
    {
        return RollEntry::query()
            ->whereNotNull('fullname')
            ->whereNotIn('matric_number', fn ($query) => $query->select('matric_number')->from('users'))
            ->where(function (Builder $query) use ($classes) {
                foreach ($classes as $class) {
                    [$programme, $level] = explode('|', $class, 2);
                    $query->orWhere(fn (Builder $query) => $query->where('programme', $programme)->where('level', $level));
                }
            });
    }
}
