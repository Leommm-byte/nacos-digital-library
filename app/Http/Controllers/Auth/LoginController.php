<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Support\Audit;
use App\Support\LoginSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LoginController extends Controller
{
    /** How long the second step may take after the password was accepted. */
    public const TWO_FACTOR_TIMEOUT_MINUTES = 5;

    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $user = $request->authenticate();

        if ($user->hasTwoFactorEnabled()) {
            // Not signed in yet: the password is right, but the second step
            // (TwoFactorChallengeController) still has to pass.
            $request->session()->put('login.pending', [
                'id' => $user->id,
                'remember' => $request->boolean('remember'),
                'expires_at' => now()->addMinutes(self::TWO_FACTOR_TIMEOUT_MINUTES)->getTimestamp(),
            ]);

            return redirect()->route('two-factor.login');
        }

        LoginSession::start($request, $user, $request->boolean('remember'));

        // Accounts created by an admin start with a temporary password; the
        // EnforceAccountState middleware sends them to change it.
        return redirect()->intended(route('home'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Audit::record('logout');

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Books kept offline are deleted by resources/js/offline.js. Nothing
        // else personal is left in the browser's cache: signed-in pages are
        // never stored (SecurityHeaders) and book files are checked with the
        // server before each use (BookFileController). (Clear-Site-Data
        // would do it too, but Chrome then goes through its whole cache,
        // every site's, and logging out took seconds.)
        return redirect()->route('login')->with('status', 'You have been logged out.');
    }
}
