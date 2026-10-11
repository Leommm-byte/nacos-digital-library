<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SessionChange;
use App\Models\User;
use App\Support\Sessions\NewSession;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

/**
 * Starting a new academic session: a preview of every class's move, one
 * confirmed step, and an undo until the first new roll is uploaded.
 */
class SessionController extends Controller
{
    public function show(): View
    {
        $current = Settings::get('academic_session');

        return view('admin.session', [
            'current' => $current,
            'next' => NewSession::nextSession($current),
            'preview' => NewSession::preview(),
            'blocker' => NewSession::blocker(),
            'undoable' => NewSession::undoable(),
            'history' => SessionChange::query()->with('starter:id,fullname')->latest('id')->limit(5)->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'to_session' => ['required', 'regex:#^20[0-9]{2}/20[0-9]{2}$#'],
            'confirm' => ['required', 'same:to_session'],
        ], [
            'to_session.regex' => 'Write the session like 2027/2028.',
            'confirm.required' => 'Type the new session to confirm.',
            'confirm.same' => 'Type the new session exactly as above to confirm.',
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $change = NewSession::start($user, (string) $data['to_session']);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['to_session' => $exception->getMessage()]);
        }

        $summary = $change->summary ?? ['promoted' => 0, 'graduated' => 0];

        return redirect()->route('admin.session')->with('status', "{$change->to_session} has started: {$summary['promoted']} moved up, {$summary['graduated']} graduated.");
    }

    public function undo(Request $request, SessionChange $change): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            NewSession::undo($change, $user);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['undo' => $exception->getMessage()]);
        }

        return redirect()->route('admin.session')->with('status', 'The new session was undone. Everyone is back where they were.');
    }
}
