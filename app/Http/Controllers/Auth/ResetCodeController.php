<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use App\Support\LoginSession;
use App\Support\MatricNumber;
use App\Support\OneTimeCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Reset with a one-time code from a course rep or admin, for students
 * without a verified email. Limited like logins: per matric number + IP,
 * and loosely per IP.
 */
class ResetCodeController extends Controller
{
    public const MAX_ATTEMPTS_PER_ACCOUNT = 5;

    public const MAX_ATTEMPTS_PER_IP = 30;

    private const INVALID = "That code isn't valid, has already been used or has expired. Ask for a new one.";

    public function create(): View
    {
        return view('auth.reset-with-code');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['matric_number' => MatricNumber::normalize($request->input('matric_number'))]);
        $request->validate([
            'matric_number' => ['required', 'string', 'max:32'],
            'code' => ['required', 'string', 'max:32'],
            'password' => ['required', 'string', 'max:255', 'confirmed', PasswordRule::defaults()],
        ]);

        $matric = $request->string('matric_number')->toString();
        $accountKey = 'reset-code:'.strtolower($matric).'|'.$request->ip();
        $ipKey = 'reset-code-ip:'.$request->ip();

        if (RateLimiter::tooManyAttempts($accountKey, self::MAX_ATTEMPTS_PER_ACCOUNT)
            || RateLimiter::tooManyAttempts($ipKey, self::MAX_ATTEMPTS_PER_IP)) {
            throw ValidationException::withMessages(['code' => 'Too many attempts. Try again in a few minutes.']);
        }

        $user = User::where('matric_number', $matric)->first();
        $code = $user?->passwordResetCodes()->usable()->latest('id')->first();

        if (! $user || ! $code || ! OneTimeCode::matches($request->string('code')->toString(), $code->code_hash)) {
            RateLimiter::hit($accountKey, 600);
            RateLimiter::hit($ipKey, 600);
            Audit::record('password_reset_code_failed', $user, ['matric_number' => $matric]);

            throw ValidationException::withMessages(['code' => self::INVALID]);
        }

        $code->forceFill(['used_at' => now()])->save();
        $user->forceFill([
            'password' => $request->string('password')->toString(),
            'must_change_password' => false,
        ])->save();

        LoginSession::endAllFor($user);
        RateLimiter::clear($accountKey);
        Audit::record('password_reset', $user, ['method' => 'code', 'issued_by' => $code->issued_by], userId: $user->id);

        return redirect()->route('login')->with('status', 'Your password has been reset. Log in with your new password.');
    }
}
