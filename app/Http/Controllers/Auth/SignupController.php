<?php

namespace App\Http\Controllers\Auth;

use App\Enums\Level;
use App\Enums\Programme;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SignupRequest;
use App\Models\Department;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
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
        $user = User::create($request->safe()->only([
            'fullname', 'matric_number', 'department_id', 'level', 'programme', 'password',
        ]));

        Audit::record('user_registered', $user, userId: $user->id);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('home')->with('status', 'Welcome to NACOS YabaTech, '.$user->firstName().'!');
    }
}
