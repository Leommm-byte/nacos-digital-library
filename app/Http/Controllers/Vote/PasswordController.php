<?php

namespace App\Http\Controllers\Vote;

use App\Http\Controllers\Controller;
use App\Models\Voter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Changing the password: required after signing in with a slip from the
 * department's admin. A forgotten password is replaced by that admin, who
 * prints a new slip (no email needed).
 */
class PasswordController extends Controller
{
    public function forgot(): View
    {
        return view('vote.auth.forgot');
    }

    public function edit(Request $request): View
    {
        /** @var Voter $voter */
        $voter = $request->user('voter');

        return view('vote.auth.password', ['forced' => $voter->must_change_password]);
    }

    public function update(Request $request): RedirectResponse
    {
        /** @var Voter $voter */
        $voter = $request->user('voter');

        $data = $request->validate([
            'current_password' => [$voter->must_change_password ? 'nullable' : 'required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        if (! $voter->must_change_password && ! Hash::check((string) $data['current_password'], $voter->password)) {
            throw ValidationException::withMessages(['current_password' => 'That isn\'t your current password.']);
        }

        if (Hash::check((string) $data['password'], $voter->password)) {
            throw ValidationException::withMessages(['password' => 'Choose a password different from the one you have now.']);
        }

        $voter->forceFill(['password' => (string) $data['password'], 'must_change_password' => false])->save();

        return redirect()->route('vote.home')->with('status', 'Password changed.');
    }
}
