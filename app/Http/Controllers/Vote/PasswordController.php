<?php

namespace App\Http\Controllers\Vote;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Voter;
use App\Notifications\VoterPasswordReset;
use App\Support\Departments\Activity;
use App\Support\MatricNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Changing the password: required after signing in with a slip from the
 * department's admin. A forgotten password is reset with an emailed link
 * when the account has an email, or replaced by that admin with a new slip.
 */
class PasswordController extends Controller
{
    /** How long an emailed link works. */
    private const LINK_MINUTES = 60;

    public function forgot(): View
    {
        return view('vote.auth.forgot');
    }

    /**
     * Emails a reset link when the account has an email. The answer is the
     * same either way, so it doesn't reveal which accounts exist.
     */
    public function sendLink(Request $request, Organization $organization): RedirectResponse
    {
        $data = $request->validate(['login' => ['required', 'string', 'max:255']], ['login.required' => 'Enter your matric number or email.']);
        $login = trim((string) $data['login']);
        $voter = $organization->voters()
            ->where(str_contains($login, '@') ? 'email' : 'matric_number', str_contains($login, '@') ? strtolower($login) : MatricNumber::normalize($login))
            ->where('status', UserStatus::Active)
            ->first();

        if ($voter instanceof Voter && $voter->email !== null) {
            $token = Str::random(64);
            DB::table('voter_password_resets')->upsert(
                [['voter_id' => $voter->id, 'token_hash' => hash('sha256', $token), 'created_at' => now()]],
                ['voter_id'],
                ['token_hash', 'created_at'],
            );
            $voter->notify(new VoterPasswordReset(route('vote.password.reset', ['voter' => $voter->id, 'token' => $token])));
            Activity::record($organization, 'password_reset_requested', $voter, [], $voter);
        }

        return back()->with('status', 'If that account has an email, a link to reset the password is on its way. No email on your account? Ask the department\'s election admins for a new password.');
    }

    public function resetForm(int $voter, string $token): View
    {
        abort_unless($this->validReset($voter, $token), 404);

        return view('vote.auth.reset', ['voter' => $voter, 'token' => $token]);
    }

    public function reset(Request $request, Organization $organization, int $voter, string $token): RedirectResponse
    {
        abort_unless($this->validReset($voter, $token), 404);

        $data = $request->validate(['password' => ['required', 'confirmed', Password::min(8)]]);

        $account = $organization->voters()->findOrFail($voter);
        $account->forceFill(['password' => (string) $data['password'], 'must_change_password' => false, 'remember_token' => null])->save();
        DB::table('voter_password_resets')->where('voter_id', $voter)->delete();
        Activity::record($organization, 'password_reset', $account, [], $account);

        return redirect()->route('vote.login')->with('status', 'Password changed. Log in with your new password.');
    }

    /**
     * A link for one of this department's accounts, unused and not expired.
     */
    private function validReset(int $voter, string $token): bool
    {
        $organization = Organization::current();
        $row = DB::table('voter_password_resets')
            ->join('voters', 'voters.id', '=', 'voter_password_resets.voter_id')
            ->where('voter_password_resets.voter_id', $voter)
            ->where('voters.organization_id', $organization?->id)
            ->first(['voter_password_resets.token_hash', 'voter_password_resets.created_at']);

        return $row !== null
            && hash_equals((string) $row->token_hash, hash('sha256', $token))
            && Carbon::parse((string) $row->created_at)->gt(now()->subMinutes(self::LINK_MINUTES));
    }

    public function edit(Request $request): View
    {
        /** @var Voter $voter */
        $voter = $request->user('voter');

        return view('vote.auth.password', ['forced' => $voter->must_change_password]);
    }

    public function update(Request $request): RedirectResponse
    {
        /** @var Voter $voter */
        $voter = $request->user('voter');

        $data = $request->validate([
            'current_password' => [$voter->must_change_password ? 'nullable' : 'required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        if (! $voter->must_change_password && ! Hash::check((string) $data['current_password'], $voter->password)) {
            throw ValidationException::withMessages(['current_password' => 'That isn\'t your current password.']);
        }

        if (Hash::check((string) $data['password'], $voter->password)) {
            throw ValidationException::withMessages(['password' => 'Choose a password different from the one you have now.']);
        }

        $forced = $voter->must_change_password;
        $voter->forceFill(['password' => (string) $data['password'], 'must_change_password' => false])->save();
        Activity::record($voter->organization_id, 'password_changed', $voter, $forced ? ['from_slip' => true] : [], $voter);

        return redirect()->route('vote.home')->with('status', 'Password changed.');
    }
}
