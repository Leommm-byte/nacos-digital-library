<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use App\Support\LoginSession;
use App\Support\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Second login step for accounts with two-step verification on.
 */
class TwoFactorChallengeController extends Controller
{
    public const MAX_ATTEMPTS = 5;

    public function create(Request $request): View|RedirectResponse
    {
        if (! $this->pendingUser($request)) {
            return $this->timedOut($request);
        }

        return view('auth.two-factor-challenge');
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->pendingUser($request);

        if (! $user) {
            return $this->timedOut($request);
        }

        $request->validate(['code' => ['required', 'string', 'max:32']]);

        $key = 'two-factor:'.$user->id.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'code' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.',
            ]);
        }

        $method = TwoFactor::verify($user, $request->string('code')->toString());

        if ($method === null) {
            RateLimiter::hit($key, 60);
            Audit::record('two_factor_failed', $user);

            throw ValidationException::withMessages([
                'code' => "That code isn't right. Check your authenticator app and try again.",
            ]);
        }

        RateLimiter::clear($key);

        $remember = (bool) $request->session()->pull('login.pending.remember', false);
        $request->session()->forget('login.pending');

        if ($user->isSuspended()) {
            return redirect()->route('login')->withErrors([
                'matric_number' => 'This account has been suspended. Contact a NACOS admin if you think this is a mistake.',
            ]);
        }

        LoginSession::start($request, $user, $remember, ['two_factor' => $method]);

        $redirect = redirect()->intended(route('home'));

        if ($method === 'recovery') {
            $left = TwoFactor::remainingRecoveryCodes($user);
            $redirect->with('error', "You used a recovery code. {$left} left. If you've lost your phone, set up two-step verification again in Account settings.");
        }

        return $redirect;
    }

    private function pendingUser(Request $request): ?User
    {
        $pending = $request->session()->get('login.pending');

        if (! is_array($pending) || ($pending['expires_at'] ?? 0) < now()->getTimestamp()) {
            return null;
        }

        $user = User::find($pending['id'] ?? null);

        return $user && $user->hasTwoFactorEnabled() ? $user : null;
    }

    private function timedOut(Request $request): RedirectResponse
    {
        $request->session()->forget('login.pending');

        return redirect()->route('login')->with('error', 'Your sign-in timed out. Log in again.');
    }
}
