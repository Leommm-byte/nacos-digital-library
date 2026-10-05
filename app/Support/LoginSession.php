<?php

namespace App\Support;

use App\Models\User;
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

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        Audit::record('login', $user, $auditMeta);
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
