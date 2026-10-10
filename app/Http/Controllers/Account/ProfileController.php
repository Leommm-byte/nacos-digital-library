<?php

namespace App\Http\Controllers\Account;

use App\Enums\Level;
use App\Enums\Programme;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\UpdateProfileRequest;
use App\Models\Department;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        $user = $this->user($request)->loadMissing('department');
        $previous = $request->session()->get('previous_login');

        return view('account.profile', [
            'user' => $user,
            'departments' => Department::where('is_active', true)
                ->orWhere('id', $user->department_id)
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all(),
            'levels' => collect(Level::cases())->mapWithKeys(fn (Level $level) => [$level->value => $level->label()])->all(),
            'programmes' => collect(Programme::cases())->mapWithKeys(fn (Programme $programme) => [$programme->value => $programme->label()])->all(),
            'classLocked' => $user->hasRole(Role::CourseRep),
            'previousLoginAt' => is_array($previous) && isset($previous['at']) ? Carbon::parse($previous['at']) : null,
            'previousLoginIp' => is_array($previous) ? ($previous['ip'] ?? null) : null,
        ]);
    }

    public function update(UpdateProfileRequest $request): RedirectResponse
    {
        $user = $this->user($request);

        $user->fill($request->safe()->only(['fullname', 'display_name', 'department_id', 'level', 'programme']));
        $changed = array_keys($user->getDirty());

        if ($changed === []) {
            return back()->with('status', 'Nothing to update.');
        }

        $user->save();
        Audit::record('profile_updated', $user, ['changed' => $changed]);

        return redirect()->route('profile.edit')->with('status', 'Your profile has been updated.');
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
