<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use App\Support\LoginSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Changing your own password while signed in. Other devices are signed
 * out; this one stays signed in.
 */
class PasswordController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validated = $request->validateWithBag('password', [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'string', 'max:255', 'confirmed', Password::defaults()],
        ], [
            'current_password.current_password' => 'That password is incorrect.',
        ]);

        if (Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'Choose a password different from your current one.',
            ])->errorBag('password');
        }

        $user->forceFill(['password' => $validated['password']])->save();

        LoginSession::endOthersFor($request, $user);
        Audit::record('password_changed', $user, ['reason' => 'self']);

        return redirect()->route('settings')->with('status', 'Your password has been changed. Any other devices have been logged out.');
    }
}
