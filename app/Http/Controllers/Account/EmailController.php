<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The email address is what password reset links go to, so changing it
 * needs the current password and a fresh verification.
 */
class EmailController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $user = $this->user($request);

        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $validated = $request->validateWithBag('email', [
            'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'current_password' => ['required', 'current_password'],
        ], [
            'email.unique' => 'Another account already uses this email address.',
            'current_password.current_password' => 'That password is incorrect.',
        ]);

        if ($validated['email'] === $user->email) {
            return back()->with('status', 'That is already your email address.');
        }

        $previous = $user->email;

        $user->forceFill([
            'email' => $validated['email'],
            'email_verified_at' => null,
        ])->save();

        $user->sendEmailVerificationNotification();
        Audit::record('email_changed', $user, ['had_email' => $previous !== null]);

        return back()->with('status', 'Check your inbox at '.$user->email.' and open the link to verify it.');
    }

    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        $request->fulfill();
        Audit::record('email_verified');

        return redirect()->route('settings')->with('status', 'Your email address is verified. You can now reset your password by email.');
    }

    public function resend(Request $request): RedirectResponse
    {
        $user = $this->user($request);

        if ($user->email === null || $user->hasVerifiedEmail()) {
            return back();
        }

        $user->sendEmailVerificationNotification();

        return back()->with('status', 'A new verification link is on its way to '.$user->email.'.');
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
