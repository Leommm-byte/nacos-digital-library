<?php

namespace App\Http\Controllers\Auth;

use App\Enums\Level;
use App\Enums\Programme;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SignupRequest;
use App\Models\Department;
use App\Models\RollEntry;
use App\Models\User;
use App\Support\Accounts\Invitations;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SignupController extends Controller
{
    public function create(): View
    {
        return view('auth.signup', [
            'departments' => Department::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'levels' => Level::cases(),
            'programmes' => Programme::cases(),
        ]);
    }

    public function store(SignupRequest $request): RedirectResponse
    {
        $rollEmail = $this->rollEmail($request->string('matric_number')->toString());

        if ($rollEmail !== null) {
            return $this->inviteFromRoll($request, $rollEmail);
        }

        $user = User::create($request->safe()->only([
            'fullname', 'display_name', 'matric_number', 'email', 'department_id', 'level', 'programme', 'password',
        ]));

        Audit::record('user_registered', $user, userId: $user->id);
        $user->sendEmailVerificationNotification();

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('home')->with('status', 'Welcome to NACOS YabaTech, '.$user->greetingName().'!');
    }

    /**
     * "Check your email", after a sign-up whose link went to the class
     * list's address.
     */
    public function sent(Request $request): View|RedirectResponse
    {
        $email = $request->session()->get('signup.sent');

        if (! is_string($email)) {
            return redirect()->route('login');
        }

        return view('auth.signup-sent', ['email' => $email]);
    }

    /**
     * The email on the class list for this matric number, if it has one
     * that no other account uses.
     */
    private function rollEmail(string $matric): ?string
    {
        $email = RollEntry::query()->where('matric_number', $matric)->value('email');

        if (! is_string($email) || $email === '' || User::query()->where('email', $email)->exists()) {
            return null;
        }

        return $email;
    }

    /**
     * The class list has this student's email, so whoever is signing up
     * must prove it's theirs: the account is made with that address and
     * no usable password, and the link to choose one goes to the address.
     * Someone typing a classmate's matric number gets nowhere.
     */
    private function inviteFromRoll(SignupRequest $request, string $email): RedirectResponse
    {
        $user = new User([
            ...$request->safe()->only(['fullname', 'display_name', 'matric_number', 'department_id', 'level', 'programme']),
            'email' => $email,
            'password' => Str::random(40),
        ]);
        $user->must_change_password = true;

        DB::transaction(function () use ($user) {
            $user->save();
            Invitations::queue($user, by: $user, priority: true);
        });

        Audit::record('user_registered', $user, ['via' => 'class_list_email'], userId: $user->id);

        return redirect()->route('signup.sent')->with('signup.sent', Invitations::mask($email));
    }
}
