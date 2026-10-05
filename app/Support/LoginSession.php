<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LoginSession
{
    /**
     * Signs the user in once every check (password, and the second step
     * when MFA is on) has passed.
     *
     * @param  array<string, mixed>  $auditMeta
     */
    public static function start(Request $request, User $user, bool $remember, array $auditMeta = []): void
    {
        Auth::login($user, $remember);
        $request->session()->regenerate();

        // Shown on the profile page so people can spot a sign-in that
        // wasn't them.
        $request->session()->put('previous_login', [
            'at' => $user->last_login_at?->toIso8601String(),
            'ip' => $user->last_login_ip,
        ]);

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        Audit::record('login', $user, $auditMeta);
    }

    /**
     * After a password change: sign out every other session and invalidate
     * "keep me logged in" cookies elsewhere, while keeping this device
     * signed in (re-issuing its own cookie if it had one).
     */
    public static function endOthersFor(Request $request, User $user): void
    {
        $guard = Auth::guard('web');
        $remembered = $guard instanceof SessionGuard && $request->cookies->has($guard->getRecallerName());

        $user->forceFill(['remember_token' => Str::random(60)])->save();

        if (config('session.driver') === 'database') {
            DB::table((string) config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->where('id', '!=', $request->session()->getId())
                ->delete();
        }

        $request->session()->regenerate();
        Auth::login($user, $remembered);
    }

    /**
     * After a password reset: sign the account out everywhere, including
     * "keep me logged in" cookies.
     */
    public static function endAllFor(User $user): void
    {
        $user->forceFill(['remember_token' => Str::random(60)])->save();

        if (config('session.driver') === 'database') {
            DB::table((string) config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }
    }
}
