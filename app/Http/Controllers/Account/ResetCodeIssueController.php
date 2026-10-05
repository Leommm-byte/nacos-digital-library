<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\PasswordResetCode;
use App\Models\User;
use App\Support\Audit;
use App\Support\MatricNumber;
use App\Support\OneTimeCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Course reps and admins issue one-time reset codes to students who can't
 * reset by email. The code is shown once, to the issuer, to pass on in
 * person; only its hash is stored.
 */
class ResetCodeIssueController extends Controller
{
    public function create(): View
    {
        return view('account.reset-codes');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['matric_number' => MatricNumber::normalize($request->input('matric_number'))]);
        $request->validate(['matric_number' => ['required', 'string', 'max:32']]);

        $target = User::where('matric_number', $request->string('matric_number')->toString())->first();

        if (! $target) {
            return back()->withInput()->withErrors(['matric_number' => 'No account has that matric number.']);
        }

        if ($request->user()?->cannot('issueResetCode', $target)) {
            return back()->withInput()->withErrors([
                'matric_number' => 'You can only issue codes for students in your own class (same department and level).',
            ]);
        }

        $code = OneTimeCode::generate();

        DB::transaction(function () use ($target, $code, $request): void {
            // Only the newest code works.
            $target->passwordResetCodes()->whereNull('used_at')->delete();

            $target->passwordResetCodes()->create([
                'issued_by' => $request->user()?->id,
                'code_hash' => OneTimeCode::hash($code),
                'expires_at' => now()->addMinutes(PasswordResetCode::LIFETIME_MINUTES),
            ]);
        });

        Audit::record('password_reset_code_issued', $target);

        return redirect()->route('reset-codes.create')->with('issued_code', [
            'code' => $code,
            'name' => $target->fullname,
            'matric_number' => $target->matric_number,
            'minutes' => PasswordResetCode::LIFETIME_MINUTES,
        ]);
    }
}
