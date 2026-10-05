<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Audit;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs on every web request for signed-in users:
 *  - a suspended account is signed out at once, even mid-session;
 *  - an account with a temporary password can only change it or log out.
 */
class EnforceAccountState
{
    /** Routes a user with a temporary password may still reach. */
    private const PASSWORD_CHANGE_ROUTES = ['password.change', 'password.change.update', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        if ($user->isSuspended()) {
            Audit::record('session_ended_suspended', $user);

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('error', 'Your account has been suspended. Contact a NACOS admin if you think this is a mistake.');
        }

        if ($user->must_change_password && ! $request->routeIs(...self::PASSWORD_CHANGE_ROUTES)) {
            return $request->expectsJson()
                ? response()->json(['message' => 'You must change your password first.'], 403)
                : redirect()->route('password.change');
        }

        return $next($request);
    }
}
