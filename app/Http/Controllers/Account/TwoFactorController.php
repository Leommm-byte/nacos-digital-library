<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use App\Support\Totp;
use App\Support\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Turning two-step verification on and off. The new secret lives only in
 * the session until the user proves their app works by entering a code;
 * turning it off needs a current code (or recovery code), not just a
 * signed-in session.
 */
class TwoFactorController extends Controller
{
    private const PENDING = 'two_factor.pending_secret';

    public function start(Request $request): RedirectResponse
    {
        $user = $this->user($request);

        if ($user->hasTwoFactorEnabled()) {
            return redirect()->route('settings');
        }

        $request->validateWithBag('twoFactor', ['current_password' => ['required', 'current_password']], [
            'current_password.current_password' => 'That password is incorrect.',
        ]);

        $request->session()->put(self::PENDING, Totp::generateSecret());

        return redirect()->route('two-factor.setup');
    }

    public function setup(Request $request): View|RedirectResponse
    {
        $user = $this->user($request);
        $secret = $request->session()->get(self::PENDING);

        if ($user->hasTwoFactorEnabled() || ! is_string($secret)) {
            return redirect()->route('settings');
        }

        return view('account.two-factor-setup', [
            'secret' => Totp::formatForDisplay($secret),
            'uri' => Totp::uri($secret, $user->matric_number, (string) config('app.name')),
        ]);
    }

    public function confirm(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $secret = $request->session()->get(self::PENDING);

        if ($user->hasTwoFactorEnabled() || ! is_string($secret)) {
            return redirect()->route('settings');
        }

        $request->validate(['code' => ['required', 'string', 'max:16']]);

        $step = Totp::verify($secret, $request->string('code')->toString(), now()->getTimestamp());

        if ($step === null) {
            throw ValidationException::withMessages([
                'code' => "That code didn't match. Check the time on your phone is set automatically, then try the newest code.",
            ]);
        }

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
            'two_factor_last_step' => $step,
        ])->save();
        $codes = TwoFactor::replaceRecoveryCodes($user);

        $request->session()->forget(self::PENDING);
        Audit::record('two_factor_enabled', $user);

        return redirect()->route('settings')
            ->with('status', 'Two-step verification is on.')
            ->with('recovery_codes', $codes);
    }

    public function regenerateRecoveryCodes(Request $request): RedirectResponse
    {
        $user = $this->requireCode($request, 'recoveryCodes');

        $codes = TwoFactor::replaceRecoveryCodes($user);
        Audit::record('two_factor_recovery_codes_regenerated', $user);

        return redirect()->route('settings')
            ->with('status', 'New recovery codes created. The old ones no longer work.')
            ->with('recovery_codes', $codes);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $this->requireCode($request, 'disableTwoFactor');

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
        ])->save();

        Audit::record('two_factor_disabled', $user);

        return redirect()->route('settings')->with('status', 'Two-step verification is off.');
    }

    /**
     * Sensitive changes need a current code from the app or a recovery code.
     */
    private function requireCode(Request $request, string $bag): User
    {
        $user = $this->user($request);
        abort_unless($user->hasTwoFactorEnabled(), 404);

        $request->validateWithBag($bag, ['code' => ['required', 'string', 'max:32']]);

        if (TwoFactor::verify($user, $request->string('code')->toString()) === null) {
            Audit::record('two_factor_failed', $user);

            throw ValidationException::withMessages([
                'code' => "That code isn't right.",
            ])->errorBag($bag);
        }

        return $user;
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
