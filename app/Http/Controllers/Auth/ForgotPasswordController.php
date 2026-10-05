<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use App\Support\MatricNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

/**
 * "Forgot password": emails a reset link to the account's verified address.
 * The answer is the same whether or not the account exists or has an email,
 * so the page can't be used to find out who is registered.
 */
class ForgotPasswordController extends Controller
{
    public const SENT = 'If that account has a verified email address, a reset link is on its way. It works for 60 minutes. No email? Ask your course rep or an admin for a reset code.';

    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['matric_number' => MatricNumber::normalize($request->input('matric_number'))]);
        $request->validate(['matric_number' => ['required', 'string', 'max:32']]);

        $matric = $request->string('matric_number')->toString();
        $user = User::where('matric_number', $matric)->first();

        if ($user && $user->hasVerifiedEmailAddress() && ! $user->isSuspended()) {
            // The broker also refuses a second link within 60 seconds.
            $status = Password::sendResetLink(['email' => $user->email]);
            Audit::record('password_reset_requested', $user, ['sent' => $status === Password::RESET_LINK_SENT]);
        } else {
            Audit::record('password_reset_requested', $user, ['sent' => false, 'matric_number' => $matric]);
        }

        return back()->with('status', self::SENT);
    }
}
