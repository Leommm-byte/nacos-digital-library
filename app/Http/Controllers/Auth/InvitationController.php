<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\RollEntry;
use App\Support\Accounts\Invitations;
use App\Support\Audit;
use App\Support\Elections\VoteHolds;
use App\Support\LoginSession;
use Illuminate\Database\QueryException;
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

        // Invitation links always go to the class-list email on the roll
        // (Invitations::sendDue), so opening one proves control of that
        // address: the account is theirs (docs/vote-integrity.md).
        $rollEmail = RollEntry::emailFor($user->matric_number);

        DB::transaction(function () use ($invitation, $user, $request, $rollEmail) {
            // The link reached them, so the address is theirs.
            $user->forceFill([
                'password' => $request->string('password')->toString(),
                'must_change_password' => false,
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();

            if ($rollEmail !== null) {
                $user->forceFill(['roll_email_verified_at' => now()])->save();

                // Put the account's email back to the roll's, in case an
                // impersonator had changed it. Best effort: another account
                // may already hold it (a shared or duplicated class email).
                if ($user->email !== $rollEmail) {
                    try {
                        $user->forceFill(['email' => $rollEmail, 'email_verified_at' => now()])->save();
                    } catch (QueryException) {
                        // Another account already holds that address (a shared
                        // or duplicated class email). Keep the current one;
                        // they're still verified. Drop the failed change from
                        // the model so a later save() (LoginSession::endAllFor)
                        // doesn't re-attempt the duplicate write and crash.
                        $user->discardChanges();
                    }
                }
            }

            $invitation->forceFill(['accepted_at' => now(), 'token_hash' => null])->save();
        });

        Audit::record('invitation_accepted', $user, userId: $user->id);

        if ($rollEmail !== null) {
            Audit::record('roll_email_verified', $user, userId: $user->id);

            // Proving the class email clears a hold placed only because the
            // account looked suspicious, and lets the vote count again.
            if ($user->isOnVoteHold() && VoteHolds::release($user, 'Verified by class email')) {
                VoteHolds::recount();
            }
        }

        // Sign out everyone else first (an impersonator's sessions), then
        // this device.
        LoginSession::endAllFor($user);
        LoginSession::start($request, $user, false);

        return redirect()->route('home')->with('status', 'Welcome to NACOS YabaTech, '.$user->greetingName().'! Log in next time with your matric number and this password.');
    }
}
