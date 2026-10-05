<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use App\Support\LoginSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

/**
 * The page the emailed link opens. Tokens are single use and expire after
 * 60 minutes (config/auth.php).
 */
class ResetPasswordController extends Controller
{
    public function create(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->string('email')->toString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'max:255', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'must_change_password' => false,
                ])->save();

                LoginSession::endAllFor($user);
                Audit::record('password_reset', $user, ['method' => 'email'], userId: $user->id);
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withErrors([
                'password' => 'This reset link is invalid or has expired. Request a new one.',
            ]);
        }

        return redirect()->route('login')->with('status', 'Your password has been reset. Log in with your new password.');
    }
}
