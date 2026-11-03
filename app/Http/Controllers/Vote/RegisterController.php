<?php

namespace App\Http\Controllers\Vote;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Voter;
use App\Rules\ValidMatricNumber;
use App\Support\Departments\Activity;
use App\Support\MatricNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Registering to vote in a department's elections: only matric numbers on
 * its nominal roll, one account each. Separate from a NACOS account.
 *
 * As with NACOS, elections by default only count accounts that existed
 * when voting opened, so nobody can register an absent classmate's matric
 * number during an election and vote as them.
 */
class RegisterController extends Controller
{
    public function create(): View
    {
        return view('vote.auth.register');
    }

    public function store(Request $request, Organization $organization): RedirectResponse
    {
        $request->merge(['matric_number' => MatricNumber::normalize($request->string('matric_number')->toString())]);

        $data = $request->validate([
            'fullname' => ['required', 'string', 'max:150'],
            'matric_number' => ['required', 'string', 'max:32', new ValidMatricNumber],
            'email' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ], [
            'fullname.required' => 'Enter your full name.',
            'matric_number.required' => 'Enter your matric number.',
        ]);

        $matric = (string) $data['matric_number'];

        if (! $organization->roll()->where('matric_number', $matric)->exists()) {
            throw ValidationException::withMessages(['matric_number' => "This matric number isn't on the {$organization->name} nominal roll. Check it, or ask your class rep to get you added."]);
        }

        if ($organization->voters()->where('matric_number', $matric)->exists()) {
            throw ValidationException::withMessages(['matric_number' => 'This matric number is already registered. Log in instead, or ask the department\'s election admin for a new password.']);
        }

        $email = isset($data['email']) ? strtolower((string) $data['email']) : null;
        if ($email !== null && $organization->voters()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'Another account here already uses this email address.']);
        }

        $voter = new Voter([
            'fullname' => (string) $data['fullname'],
            'matric_number' => $matric,
            'email' => $email,
            'password' => (string) $data['password'],
        ]);
        $voter->organization_id = $organization->id;
        $voter->last_login_at = now();
        $voter->save();
        Activity::record($organization, 'voter_registered', $voter, ['matric' => $matric, 'name' => $voter->fullname, 'email' => $email !== null ? 'given' : 'none'], $voter);

        Auth::guard('voter')->login($voter);
        $request->session()->regenerate();

        return redirect()->route('vote.home')->with('status', "You're registered, {$voter->firstName()}. You can vote in {$organization->name} elections you're eligible for.");
    }
}
