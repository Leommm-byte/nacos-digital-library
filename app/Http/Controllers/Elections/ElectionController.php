<?php

namespace App\Http\Controllers\Elections;

use App\Enums\ElectionStatus;
use App\Http\Controllers\Controller;
use App\Models\Election;
use App\Models\User;
use App\Support\Audit;
use App\Support\Elections\AlreadyVoted;
use App\Support\Elections\BallotBox;
use App\Support\Elections\BallotRejected;
use App\Support\Elections\LiveResults;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Elections as students see them: the open ones with their ballot, and the
 * results of open and past ones. Results are public (decided with the
 * owner, for transparency) and come from the published snapshot, never
 * from live counts.
 */
class ElectionController extends Controller
{
    public function index(Request $request): View
    {
        $open = Election::query()->where('status', ElectionStatus::Open)->orderBy('ends_at')->get();
        $past = Election::query()->where('status', ElectionStatus::Closed)->latest('closed_at')->limit(12)->get();

        /** @var User|null $user */
        $user = $request->user();
        $voted = $user === null ? [] : DB::table('election_voters')
            ->where('user_id', $user->id)
            ->whereIn('election_id', $open->pluck('id'))
            ->pluck('election_id')
            ->map(fn ($id) => (int) $id)
            ->flip()
            ->all();

        return view('elections.index', [
            'open' => $open,
            'past' => $past,
            'voted' => $voted,
            'results' => $open->concat($past)->mapWithKeys(fn (Election $election) => [$election->id => LiveResults::read($election)])->all(),
            'user' => $user,
        ]);
    }

    public function show(Request $request, Election $election): View
    {
        abort_unless(Gate::allows('view', $election), 404);

        $election->load('positions.candidates');

        /** @var User|null $user */
        $user = $request->user();

        return view('elections.show', [
            'election' => $election,
            'results' => LiveResults::read($election),
            'user' => $user,
            'hasVoted' => $user !== null && $election->hasVoted($user),
            'reason' => $user !== null ? $election->ineligibilityReason($user) : null,
        ]);
    }

    public function vote(Request $request, Election $election): RedirectResponse
    {
        abort_unless(Gate::allows('view', $election), 404);

        $request->validate([
            'choices' => ['nullable', 'array', 'max:50'],
        ]);

        /** @var User $user */
        $user = $request->user();
        /** @var array<int|string, mixed> $choices */
        $choices = (array) $request->input('choices', []);

        try {
            BallotBox::cast($election, $user, $choices);
        } catch (AlreadyVoted $exception) {
            return redirect()->route('elections.show', $election)->with('status', $exception->getMessage());
        } catch (BallotRejected $exception) {
            return redirect()->route('elections.show', $election)->withInput()->withErrors(['ballot' => $exception->getMessage()]);
        }

        // Records that this person voted (as election_voters does), never how.
        Audit::record('election_voted', $election, ['title' => $election->title]);
        LiveResults::refresh($election);

        return redirect()->route('elections.show', $election)->with('voted', true);
    }
}
