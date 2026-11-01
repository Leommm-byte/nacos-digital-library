<?php

namespace App\Http\Controllers\Vote;

use App\Enums\ElectionStatus;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Vote\EnsureDepartmentAdmin;
use App\Models\Election;
use App\Models\ElectionCandidate;
use App\Models\ElectionPosition;
use App\Models\Organization;
use App\Models\Voter;
use App\Support\Elections\AlreadyVoted;
use App\Support\Elections\BallotBox;
use App\Support\Elections\BallotRejected;
use App\Support\Elections\LiveResults;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * A department's election as its students see it: the ballot while voting
 * is open, and the results (public, from the published snapshot, as for
 * NACOS's own elections). Route binding only finds this department's
 * elections.
 */
class ElectionController extends Controller
{
    public function show(Request $request, Organization $organization, Election $election): View
    {
        $voter = $request->user('voter');
        $voter = $voter instanceof Voter ? $voter : null;
        abort_if($election->isDraft() && ! $voter?->isAdmin() && ! EnsureDepartmentAdmin::nacosAdmin(), 404);

        $election->load('positions.candidates');

        return view('vote.election', [
            'election' => $election,
            'results' => LiveResults::read($election),
            'voter' => $voter,
            'hasVoted' => $voter !== null && $election->hasVoted($voter),
            'reason' => $voter !== null ? $election->ineligibilityReason($voter) : null,
        ]);
    }

    public function vote(Request $request, Organization $organization, Election $election): RedirectResponse
    {
        abort_if($election->isDraft(), 404);

        $request->validate([
            'choices' => ['nullable', 'array', 'max:50'],
        ]);

        /** @var Voter $voter */
        $voter = $request->user('voter');
        /** @var array<int|string, mixed> $choices */
        $choices = (array) $request->input('choices', []);

        try {
            BallotBox::cast($election, $voter, $choices);
        } catch (AlreadyVoted $exception) {
            return redirect()->route('vote.elections.show', $election)->with('status', $exception->getMessage());
        } catch (BallotRejected $exception) {
            return redirect()->route('vote.elections.show', $election)->withInput()->withErrors(['ballot' => $exception->getMessage()]);
        }

        LiveResults::refresh($election);

        return redirect()->route('vote.elections.show', $election)->with('voted', true);
    }

    /**
     * Candidate photos: public once voting opens; a draft's only to the
     * department's admins.
     */
    public function photo(Request $request, Organization $organization, Election $election, int $candidate): Response
    {
        $draft = $election->status === ElectionStatus::Draft;
        $voter = $request->user('voter');
        abort_if($draft && ! ($voter instanceof Voter && $voter->isAdmin()) && ! EnsureDepartmentAdmin::nacosAdmin(), 404);

        $candidate = ElectionCandidate::query()
            ->whereKey($candidate)
            ->whereIn('position_id', ElectionPosition::query()->where('election_id', $election->id)->select('id'))
            ->firstOrFail();

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('private');

        abort_if($candidate->photo_path === null || ! $disk->exists($candidate->photo_path), 404);

        $current = $request->query('v') === substr(sha1($candidate->photo_path), 0, 10);

        return $disk->response($candidate->photo_path, null, [
            'Cache-Control' => $current && ! $draft ? 'public, max-age=31536000, immutable' : 'private, no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
        ]);
    }
}
