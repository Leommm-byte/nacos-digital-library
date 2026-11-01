<?php

namespace App\Http\Controllers\Vote\Admin;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Voter;
use App\Support\MatricNumber;
use App\Support\TemporaryPassword;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Who has registered in the department's space, and how many of the roll
 * haven't. A forgotten password is replaced here with a printed slip;
 * an account can be suspended (it can't vote while suspended).
 */
class VoterController extends Controller
{
    public function index(Request $request, Organization $organization): View
    {
        $search = trim($request->string('q')->toString());

        $voters = $organization->voters()
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('fullname', 'like', '%'.$search.'%')
                        ->orWhere('matric_number', 'like', '%'.MatricNumber::normalize($search).'%')
                        ->orWhere('email', 'like', '%'.strtolower($search).'%');
                });
            })
            ->orderByRaw("case role when 'admin' then 0 else 1 end")
            ->orderBy('fullname')
            ->paginate(25)
            ->withQueryString();

        $rollTotal = $organization->roll()->count();
        $registered = $organization->roll()->whereIn('matric_number', $organization->voters()->whereNotNull('matric_number')->select('matric_number'))->count();

        return view('vote.admin.voters', [
            'voters' => $voters,
            'search' => $search,
            'rollTotal' => $rollTotal,
            'registered' => $registered,
            'slip' => session('slip'),
        ]);
    }

    /**
     * A new temporary password on a slip, shown once; the old one stops
     * working and they choose their own when they sign in.
     */
    public function password(Request $request, Organization $organization, int $voter): RedirectResponse
    {
        $voter = $this->find($request, $organization, $voter);
        $password = TemporaryPassword::make();
        $voter->forceFill(['password' => $password, 'must_change_password' => true, 'remember_token' => null])->save();

        return redirect()->route('vote.admin.voters', ['q' => $voter->matric_number ?? $voter->email])->with('slip', [
            'name' => $voter->fullname,
            'login' => $voter->matric_number ?? (string) $voter->email,
            'password' => $password,
        ]);
    }

    public function status(Request $request, Organization $organization, int $voter): RedirectResponse
    {
        $voter = $this->find($request, $organization, $voter);
        $suspend = $request->input('status') === UserStatus::Suspended->value;
        $voter->forceFill(['status' => $suspend ? UserStatus::Suspended : UserStatus::Active, 'remember_token' => null])->save();

        return back()->with('status', $suspend ? "{$voter->fullname} is suspended and can't vote." : "{$voter->fullname} is active again.");
    }

    /**
     * One of this department's accounts, never the admin's own.
     */
    private function find(Request $request, Organization $organization, int $id): Voter
    {
        $voter = $organization->voters()->findOrFail($id);
        abort_if($voter->is($request->user('voter')), 403, 'Ask another admin to change your own account.');

        return $voter;
    }
}
