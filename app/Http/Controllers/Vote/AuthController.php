<?php

namespace App\Http\Controllers\Vote;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Voter;
use App\Support\MatricNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Signing in to a department's election space: students with their matric
 * number, the department's admins with their matric number or email.
 *
 * Only failed attempts count, as for NACOS (LoginRequest): per account and
 * address, and a looser limit per address, because a whole campus can share
 * one IP on election day.
 */
class AuthController extends Controller
{
    private const MAX_ATTEMPTS_PER_ACCOUNT = 5;

    private const MAX_ATTEMPTS_PER_IP = 100;

    /** Checked when the account is unknown, so timing doesn't reveal which exist. */
    private const DUMMY_HASH = '$2y$12$7DpYq2Wv8yzFkjW1qNBpyu7HHRDbUUolIAri7MfvDrv2fazZYLPFu';

    public function create(): View
    {
        return view('vote.auth.login');
    }

    public function store(Request $request, Organization $organization): RedirectResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ], [
            'login.required' => 'Enter your matric number.',
        ]);

        $login = trim((string) $data['login']);
        $login = str_contains($login, '@') ? strtolower($login) : MatricNumber::normalize($login);
        $accountKey = "vote-login:{$organization->id}:{$login}|{$request->ip()}";
        $ipKey = "vote-login-ip:{$request->ip()}";

        $account = RateLimiter::tooManyAttempts($accountKey, self::MAX_ATTEMPTS_PER_ACCOUNT);
        $ip = RateLimiter::tooManyAttempts($ipKey, self::MAX_ATTEMPTS_PER_IP);

        if ($account || $ip) {
            $seconds = max($account ? RateLimiter::availableIn($accountKey) : 0, $ip ? RateLimiter::availableIn($ipKey) : 0);

            throw ValidationException::withMessages(['login' => 'Too many attempts. Try again in '.($seconds > 60 ? ceil($seconds / 60).' minutes' : $seconds.' seconds').'.']);
        }

        $voter = $organization->voters()->where(str_contains($login, '@') ? 'email' : 'matric_number', $login)->first();

        if (! Hash::check((string) $data['password'], $voter->password ?? self::DUMMY_HASH) || ! $voter instanceof Voter) {
            RateLimiter::hit($accountKey, 60);
            RateLimiter::hit($ipKey, 600);

            throw ValidationException::withMessages(['login' => 'That matric number and password don\'t match. Check both and try again.']);
        }

        RateLimiter::clear($accountKey);

        if ($voter->status !== UserStatus::Active) {
            throw ValidationException::withMessages(['login' => 'This account has been suspended. Ask the department\'s election admin.']);
        }

        Auth::guard('voter')->login($voter, $request->boolean('remember'));
        $request->session()->regenerate();
        $voter->forceFill(['last_login_at' => now()])->save();

        return $voter->must_change_password
            ? redirect()->route('vote.password.edit')
            : redirect()->intended(route('vote.home'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('voter')->logout();
        // Only this space's sign-in ends: someone also signed in to the
        // NACOS app on this browser stays signed in there.
        $request->session()->regenerateToken();

        return redirect()->route('vote.home');
    }
}
