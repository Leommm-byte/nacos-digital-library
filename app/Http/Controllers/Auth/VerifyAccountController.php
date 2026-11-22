<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\RollEntry;
use App\Models\User;
use App\Rules\ValidMatricNumber;
use App\Support\Accounts\Invitations;
use App\Support\Audit;
use App\Support\MatricNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "Verify / reclaim my account". A student enters their matric number and
 * we email a one-time link to the class-list email on the nominal roll:
 * the one address an impersonator can't set (docs/vote-integrity.md).
 * Opening the link (InvitationController) hands them the account, sets a
 * new password, marks it verified, and releases any hold.
 *
 * This replaces admins issuing reset codes one by one, and lets voting be
 * gated on proof that the voter controls that email.
 */
class VerifyAccountController extends Controller
{
    public function create(): View
    {
        return view('auth.verify-account');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(
            ['matric_number' => ['required', 'string', new ValidMatricNumber]],
            ['matric_number.required' => 'Enter your matric number.'],
        );

        $matric = MatricNumber::normalize((string) $data['matric_number']);
        $rollEmail = RollEntry::emailFor($matric);
        $user = User::query()->where('matric_number', $matric)->first();

        // No class email on the roll for this matric: nothing to send to.
        // Kept vague so the page can't be used to probe the roll.
        if ($rollEmail === null) {
            return back()->withInput()->with('verify.problem',
                "We couldn't start verification for that matric number. If you're sure it's on your class list, ask a NACOS admin to check your email on the roll.");
        }

        // On the roll but no account yet: signing up sends the same link to
        // the same class email, so point them there.
        if ($user === null) {
            return back()->with('verify.signup', true);
        }

        Invitations::queue($user, by: $user, priority: true);
        Audit::record('verification_requested', $user, ['matric' => $matric], userId: $user->id);

        return redirect()->route('verify-account.sent')->with('verify.sent', Invitations::mask($rollEmail));
    }

    public function sent(Request $request): View|RedirectResponse
    {
        $email = $request->session()->get('verify.sent');

        if (! is_string($email)) {
            return redirect()->route('verify-account');
        }

        return view('auth.verify-account-sent', ['email' => $email]);
    }
}
