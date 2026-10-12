<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\Accounts\Invitations;
use App\Support\Audit;
use App\Support\LoginSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

/**
 * The page an invitation email opens: the student chooses their password
 * and is signed in. The link works once, for 7 days.
 */
class InvitationController extends Controller
{
    public function show(string $token): View|Response
    {
        $invitation = Invitations::find($token);

        if ($invitation === null) {
            return response()->view('auth.invitation-expired', status: 410);
        }

        return view('auth.invitation', ['token' => $token, 'user' => $invitation->user]);
    }

    public function store(Request $request, string $token): RedirectResponse|Response
    {
        $invitation = Invitations::find($token);

        if ($invitation === null) {
            return response()->view('auth.invitation-expired', status: 410);
        }

        $request->validate([
            'password' => ['required', 'string', 'max:255', 'confirmed', PasswordRule::defaults()],
        ]);

        $user = $invitation->user;

        DB::transaction(function () use ($invitation, $user, $request) {
            // The link reached them, so the address is theirs.
            $user->forceFill([
                'password' => $request->string('password')->toString(),
                'must_change_password' => false,
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();

            $invitation->forceFill(['accepted_at' => now(), 'token_hash' => null])->save();
        });

        Audit::record('invitation_accepted', $user, userId: $user->id);
        LoginSession::start($request, $user, false);

        return redirect()->route('home')->with('status', 'Welcome to NACOS YabaTech, '.$user->greetingName().'! Log in next time with your matric number and this password.');
    }
}
