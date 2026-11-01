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
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Signing in to a department's election space: students with their matric
 * number, the department's admins with their matric number or email.
 */
class AuthController extends Controller
{
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
        $voter = $organization->voters()
            ->where(str_contains($login, '@') ? 'email' : 'matric_number', str_contains($login, '@') ? strtolower($login) : MatricNumber::normalize($login))
            ->first();

        if (! $voter instanceof Voter || ! Hash::check((string) $data['password'], $voter->password)) {
            throw ValidationException::withMessages(['login' => 'That matric number and password don\'t match. Check both and try again.']);
        }

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
