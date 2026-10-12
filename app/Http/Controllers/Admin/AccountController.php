<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Level;
use App\Enums\Programme;
use App\Http\Controllers\Controller;
use App\Models\AccountInvitation;
use App\Models\Department;
use App\Models\RollEntry;
use App\Models\User;
use App\Support\Accounts\Invitations;
use App\Support\Audit;
use App\Support\Classes\Arms;
use App\Support\Classes\SchoolClass;
use App\Support\TemporaryPassword;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Creates accounts for students on the nominal roll who don't have one,
 * class by class: with a random first password printed on a slip (the
 * legacy app used the surname), changed at first login; or, for those with
 * an email on the roll, an emailed invitation to choose their own
 * (App\Support\Accounts\Invitations).
 *
 * Batches of 100, so a request stays well inside shared hosting's time
 * limit; the page offers the next batch.
 */
class AccountController extends Controller
{
    public const BATCH = 100;

    public function index(): View
    {
        $order = array_map(fn (SchoolClass $class) => $class->key(), SchoolClass::all());
        $classes = DB::table('nominal_roll as r')
            ->leftJoin('users as u', 'u.matric_number', '=', 'r.matric_number')
            ->whereNotNull('r.programme')
            ->whereNotNull('r.level')
            ->selectRaw('r.programme, r.level, r.arm, count(*) as total, sum(case when u.id is null then 1 else 0 end) as missing, sum(case when u.id is null and r.fullname is null then 1 else 0 end) as no_name, sum(case when u.id is null and r.fullname is not null and r.email is not null then 1 else 0 end) as with_email')
            ->groupBy('r.programme', 'r.level', 'r.arm')
            ->get()
            ->map(fn ($row) => [
                'key' => $row->programme.'|'.$row->level.'|'.$row->arm,
                'label' => implode(' ', array_filter([
                    Level::tryFrom((string) $row->level)?->label() ?? $row->level,
                    Arms::short($row->arm),
                    Programme::tryFrom((string) $row->programme)?->label() ?? $row->programme,
                ])),
                'total' => (int) $row->total,
                'missing' => (int) $row->missing,
                'noName' => (int) $row->no_name,
                'withEmail' => (int) $row->with_email,
            ])
            // In the school's order: programme, level, course.
            ->sortBy(fn (array $class) => array_search($class['key'], $order, true) === false ? PHP_INT_MAX : array_search($class['key'], $order, true))
            ->values();

        return view('admin.accounts.index', [
            'classes' => $classes,
            'departments' => Department::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(),
            'unused' => AccountInvitation::query()->unused()->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'classes' => ['required', 'array', 'min:1'],
            'classes.*' => ['string', 'regex:/^[a-z_]+\|[A-Z0-9]+(\|[a-z0-9_]*)?$/'],
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')->where('is_active', true)],
            'send' => ['nullable', Rule::in(['slips', 'email'])],
        ], [
            'classes.required' => 'Tick at least one class.',
        ]);

        /** @var list<string> $classes */
        $classes = $data['classes'];
        $byEmail = ($data['send'] ?? 'slips') === 'email';
        $admin = $request->user();
        $pending = $this->waiting($classes);
        $batch = (clone $pending)->orderBy('matric_number')->limit(self::BATCH)->get();

        if ($batch->isEmpty()) {
            return back()->withErrors(['classes' => 'Everyone named on the roll in these classes already has an account.']);
        }

        // Cheaper hashing for first passwords (changed at first login), so
        // a batch stays quick; never above the configured cost.
        $rounds = min(10, (int) config('hashing.bcrypt.rounds', 12));
        /** @var array<string, true> $takenEmails */
        $takenEmails = array_fill_keys(User::query()->whereIn('email', $batch->pluck('email')->filter()->all())->pluck('email')->all(), true);
        $slips = [];
        $invited = 0;

        DB::transaction(function () use ($batch, $data, $rounds, $byEmail, $admin, &$takenEmails, &$slips, &$invited) {
            foreach ($batch as $entry) {
                // An address already used (by an account, or twice on the
                // roll) is left off; they can add theirs later.
                $email = $entry->email !== null && ! isset($takenEmails[$entry->email]) ? $entry->email : null;
                if ($email !== null) {
                    $takenEmails[$email] = true;
                }

                // Emailed a link to choose their own password; nobody, the
                // admin included, ever sees one. Without an address: a slip.
                $invite = $byEmail && $email !== null;
                $password = $invite ? Str::random(40) : TemporaryPassword::make();

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

                if ($invite) {
                    Invitations::queue($user, by: $admin);
                    $invited++;

                    continue;
                }

                $slips[] = [
                    'name' => $user->fullname,
                    'matric' => $user->matric_number,
                    'password' => $password,
                    'class' => $entry->classLabel(),
                ];
            }
        });

        Audit::record('accounts_created', null, ['count' => count($slips) + $invited, 'invited' => $invited, 'classes' => $classes, 'department_id' => (int) $data['department_id']]);

        $remaining = $this->waiting($classes)->count();

        if ($slips === []) {
            $status = $invited.' '.Str::plural('account', $invited).' created. '.self::sendingTime();
            if ($remaining > 0) {
                $status .= ' '.number_format($remaining).' more in these classes still need an account.';
            }

            return redirect()->route('admin.accounts.invitations')->with('status', $status)->with('next', $remaining > 0 ? [
                'classes' => $classes,
                'department_id' => (int) $data['department_id'],
            ] : null);
        }

        return redirect()->route('admin.accounts.slips')->with('slips', [
            'slips' => $slips,
            'invited' => $invited,
            'remaining' => $remaining,
            'classes' => $classes,
            'department_id' => (int) $data['department_id'],
            'send' => $byEmail ? 'email' : 'slips',
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
     * Invitations nobody has used yet: waiting to be sent, sent, or
     * expired, with a one-click re-send.
     */
    public function invitations(Request $request): View
    {
        $status = $request->string('status')->toString();
        $now = now();

        $invitations = AccountInvitation::query()->unused()
            ->with('user:id,fullname,display_name,matric_number,email,programme,level,arm,graduated_at')
            ->when($status === AccountInvitation::WAITING, fn (Builder $q) => $q->whereNull('sent_at'))
            ->when($status === AccountInvitation::SENT, fn (Builder $q) => $q->whereNotNull('sent_at')->where('expires_at', '>', $now))
            ->when($status === AccountInvitation::EXPIRED, fn (Builder $q) => $q->whereNotNull('sent_at')->where('expires_at', '<=', $now))
            ->orderByRaw('sent_at is null desc')
            ->orderByDesc('sent_at')
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString();

        $counts = AccountInvitation::query()->unused()->toBase()
            ->selectRaw('sum(case when sent_at is null then 1 else 0 end) as waiting')
            ->selectRaw('sum(case when sent_at is not null and expires_at > ? then 1 else 0 end) as sent', [$now])
            ->selectRaw('sum(case when sent_at is not null and expires_at <= ? then 1 else 0 end) as expired', [$now])
            ->first();

        return view('admin.accounts.invitations', [
            'invitations' => $invitations,
            'status' => $status,
            'counts' => [
                AccountInvitation::WAITING => (int) ($counts->waiting ?? 0),
                AccountInvitation::SENT => (int) ($counts->sent ?? 0),
                AccountInvitation::EXPIRED => (int) ($counts->expired ?? 0),
            ],
            'accepted' => AccountInvitation::query()->whereNotNull('accepted_at')->count(),
            'perHour' => (int) config('mail.invitations.per_hour'),
        ]);
    }

    /**
     * Sends an invitation again, with a new link, ahead of the queue;
     * optionally to a corrected address.
     */
    public function resend(Request $request, AccountInvitation $invitation): RedirectResponse
    {
        abort_if($invitation->accepted_at !== null, 404);

        $user = $invitation->user;
        $bag = 'invitation-'.$invitation->id;
        $data = $request->validateWithBag($bag, [
            'email' => ['nullable', 'string', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ], [
            'email.unique' => 'Another account already uses this email address.',
        ]);

        $email = isset($data['email']) ? strtolower(trim((string) $data['email'])) : null;
        if ($email !== null && $email !== $user->email) {
            Audit::record('user_email_changed', $user, ['from' => $user->email, 'to' => $email]);
            $user->forceFill(['email' => $email, 'email_verified_at' => null])->save();
        }

        if ($user->email === null) {
            return back()->withErrors(['email' => 'Enter their email address.'], $bag);
        }

        $invitation->resend();
        Audit::record('invitation_resent', $user);

        return back()->with('status', "Invitation to {$user->fullname} queued. It goes out within a few minutes.");
    }

    /**
     * Sends every expired invitation again.
     */
    public function resendExpired(): RedirectResponse
    {
        $expired = AccountInvitation::query()->unused()->whereNotNull('sent_at')->where('expires_at', '<=', now());
        $count = (clone $expired)->count();
        $expired->update(['sent_at' => null, 'expires_at' => null, 'token_hash' => null]);

        Audit::record('invitations_resent', null, ['count' => $count]);

        return back()->with('status', $count.' expired '.Str::plural('invitation', $count).' queued again. '.self::sendingTime());
    }

    /**
     * "They'll all be sent in about 3 hours."
     */
    public static function sendingTime(): string
    {
        $waiting = AccountInvitation::query()->waiting()->count();
        $hours = (int) ceil($waiting / max(1, (int) config('mail.invitations.per_hour')));

        return match (true) {
            $waiting === 0 => 'All invitations are sent.',
            $hours <= 1 => 'The invitations go out within the hour.',
            default => 'The invitations go out about '.config('mail.invitations.per_hour')." an hour, so they'll all be sent in about {$hours} hours.",
        };
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
                    [$programme, $level, $arm] = array_pad(explode('|', $class, 3), 3, '');
                    $query->orWhere(fn (Builder $query) => $query->where('programme', $programme)->where('level', $level)
                        ->when($arm === '', fn ($query) => $query->whereNull('arm'), fn ($query) => $query->where('arm', $arm)));
                }
            });
    }
}
