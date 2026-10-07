<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Level;
use App\Enums\Programme;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\RollEntry;
use App\Models\User;
use App\Support\Audit;
use App\Support\MatricNumber;
use App\Support\TemporaryPassword;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Accounts: find people, change their role, class or status, turn off
 * two-step verification when they lose their phone, and give a new
 * password on a printable slip. Admins can't do any of this to themselves.
 */
class UserController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim($request->string('q')->toString());
        $filters = $request->validate([
            'role' => ['nullable', Rule::enum(Role::class)],
            'status' => ['nullable', Rule::enum(UserStatus::class)],
            'level' => ['nullable', Rule::enum(Level::class)],
            'programme' => ['nullable', Rule::enum(Programme::class)],
        ]);

        $users = User::query()
            ->with('department:id,name')
            ->when($search !== '', function (Builder $query) use ($search) {
                $query->where(function (Builder $query) use ($search) {
                    $query->where('fullname', 'like', '%'.$search.'%')
                        ->orWhere('matric_number', 'like', '%'.MatricNumber::normalize($search).'%')
                        ->orWhere('email', 'like', '%'.strtolower($search).'%');
                });
            })
            ->when($filters['role'] ?? null, fn (Builder $query, $role) => $query->where('role', $role))
            ->when($filters['status'] ?? null, fn (Builder $query, $status) => $query->where('status', $status))
            ->when($filters['level'] ?? null, fn (Builder $query, $level) => $query->where('level', $level))
            ->when($filters['programme'] ?? null, fn (Builder $query, $programme) => $query->where('programme', $programme))
            ->orderBy('fullname')
            ->paginate(25)
            ->withQueryString();

        return view('admin.users.index', [
            'users' => $users,
            'search' => $search,
            'filters' => $filters,
        ]);
    }

    public function show(User $user): View
    {
        $user->load('department:id,name');

        return view('admin.users.show', [
            'user' => $user,
            'roll' => RollEntry::query()->where('matric_number', $user->matric_number)->first(),
            'uploads' => $user->uploads()->count(),
            'activity' => AuditLog::query()
                ->where(fn ($query) => $query->where('user_id', $user->id)->orWhere(fn ($query) => $query->where('subject_type', 'User')->where('subject_id', $user->id)))
                ->with('user:id,fullname')
                ->latest('id')
                ->limit(10)
                ->get(),
            'departments' => Department::query()->orderBy('name')->pluck('name', 'id')->all(),
        ]);
    }

    public function updateRole(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('manage', $user);

        $data = $request->validate(['role' => ['required', Rule::enum(Role::class)]]);
        $from = $user->role;
        $user->role = Role::from((string) $data['role']);
        $user->save();

        Audit::record('user_role_changed', $user, ['from' => $from->value, 'to' => $user->role->value, 'name' => $user->fullname]);

        return back()->with('status', "{$user->firstName()} is now {$this->article($user->role->label())}.");
    }

    public function updateStatus(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('manage', $user);

        $suspend = $request->input('status') === UserStatus::Suspended->value;
        $user->status = $suspend ? UserStatus::Suspended : UserStatus::Active;
        $user->save();

        Audit::record($suspend ? 'user_suspended' : 'user_reactivated', $user, ['name' => $user->fullname]);

        return back()->with('status', $suspend
            ? "{$user->firstName()}'s account is suspended. They're signed out and can't log in."
            : "{$user->firstName()}'s account is active again.");
    }

    public function updateClass(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('manage', $user);

        $data = $request->validate([
            'level' => ['required', Rule::enum(Level::class)],
            'programme' => ['required', Rule::enum(Programme::class)],
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')],
        ]);

        $from = $user->level->value.' '.$user->programme->value;
        $user->update([
            'level' => $data['level'],
            'programme' => $data['programme'],
            'department_id' => (int) $data['department_id'],
        ]);

        Audit::record('user_class_changed', $user, ['from' => $from, 'to' => $user->level->value.' '.$user->programme->value, 'name' => $user->fullname]);

        return back()->with('status', 'Class saved.');
    }

    public function resetTwoFactor(User $user): RedirectResponse
    {
        Gate::authorize('manage', $user);

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
        ])->save();

        Audit::record('user_two_factor_reset', $user, ['name' => $user->fullname]);

        return back()->with('status', "Two-step verification is off for {$user->firstName()}. They can turn it on again in their settings.");
    }

    /**
     * A new random password, shown once on a slip to hand over. The person
     * has to choose their own when they next log in.
     */
    public function resetPassword(User $user): RedirectResponse
    {
        Gate::authorize('manage', $user);

        $password = TemporaryPassword::make();
        $user->forceFill([
            'password' => Hash::make($password),
            'must_change_password' => true,
        ])->save();

        Audit::record('user_password_reset', $user, ['name' => $user->fullname]);

        return back()->with('slip', [
            'name' => $user->fullname,
            'matric' => $user->matric_number,
            'password' => $password,
        ]);
    }

    private function article(string $word): string
    {
        return (in_array(strtolower($word[0]), ['a', 'e', 'i', 'o', 'u'], true) ? 'an ' : 'a ').strtolower($word);
    }
}
