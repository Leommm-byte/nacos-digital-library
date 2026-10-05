<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Required step for accounts given a temporary password (bulk import, admin
 * reset). The temporary password was just used to sign in, so it isn't
 * asked for again.
 */
class ChangePasswordController extends Controller
{
    public function edit(Request $request): View|RedirectResponse
    {
        if (! $this->user($request)->must_change_password) {
            return redirect()->route('home');
        }

        return view('auth.change-password');
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $this->user($request);

        if (! $user->must_change_password) {
            return redirect()->route('home');
        }

        $validated = $request->validate([
            'password' => ['required', 'string', 'max:255', 'confirmed', Password::defaults()],
        ]);

        if (Hash::check($validated['password'], $user->password)) {
            return back()->withErrors(['password' => 'Choose a password different from your temporary one.']);
        }

        $user->forceFill([
            'password' => $validated['password'],
            'must_change_password' => false,
        ])->save();

        $request->session()->regenerate();
        Audit::record('password_changed', $user, ['reason' => 'temporary']);

        return redirect()->intended(route('home'))->with('status', 'Your password has been changed.');
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
