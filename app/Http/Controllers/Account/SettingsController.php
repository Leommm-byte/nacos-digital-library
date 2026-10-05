<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\TwoFactor;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return view('account.settings', [
            'user' => $user,
            'recoveryCodesLeft' => TwoFactor::remainingRecoveryCodes($user),
        ]);
    }
}
