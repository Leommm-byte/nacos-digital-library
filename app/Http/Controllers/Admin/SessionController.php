<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Programme;
use App\Http\Controllers\Controller;
use App\Models\SessionChange;
use App\Models\User;
use App\Support\Sessions\NewSession;
use App\Support\Sessions\ProgrammeSessions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

/**
 * Starting a new academic session, one programme at a time (full-time,
 * part-time and CODFEL keep separate calendars): a preview of every
 * class's move, one confirmed step, and an undo until the programme's
 * first new roll is uploaded.
 */
class SessionController extends Controller
{
    public function show(Request $request): View
    {
        $programme = Programme::tryFrom($request->string('programme')->toString()) ?? Programme::FullTime;
        $current = ProgrammeSessions::session($programme);

        return view('admin.session', [
            'programme' => $programme,
            'tabs' => array_map(fn (Programme $p) => ['programme' => $p, 'session' => ProgrammeSessions::session($p)], Programme::cases()),
            'current' => $current,
            'next' => NewSession::nextSession($current),
            'preview' => NewSession::preview($programme),
            'blocker' => NewSession::blocker($programme),
            'undoable' => NewSession::undoable($programme),
            'history' => SessionChange::query()->with('starter:id,fullname')->where('programme', $programme)->latest('id')->limit(5)->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'programme' => ['required', Rule::enum(Programme::class)],
            'to_session' => ['required', 'regex:#^20[0-9]{2}/20[0-9]{2}$#'],
            'confirm' => ['required', 'same:to_session'],
        ], [
            'to_session.regex' => 'Write the session like 2027/2028.',
            'confirm.required' => 'Type the new session to confirm.',
            'confirm.same' => 'Type the new session exactly as above to confirm.',
        ]);

        /** @var User $user */
        $user = $request->user();
        $programme = Programme::from((string) $data['programme']);

        try {
            $change = NewSession::start($user, $programme, (string) $data['to_session']);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['to_session' => $exception->getMessage()]);
        }

        $summary = $change->summary ?? ['promoted' => 0, 'graduated' => 0];

        return redirect()->route('admin.session', ['programme' => $programme->value])
            ->with('status', "{$programme->label()} {$change->to_session} has started: {$summary['promoted']} moved up, {$summary['graduated']} graduated.");
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

        return redirect()->route('admin.session', ['programme' => $change->programme?->value])
            ->with('status', 'The new session was undone. Everyone in '.($change->programme?->label() ?? 'the programme').' is back where they were.');
    }
}
