<?php

namespace App\Http\Controllers\Vote;

use App\Enums\ElectionStatus;
use App\Http\Controllers\Controller;
use App\Models\Election;
use App\Models\Organization;
use App\Models\Voter;
use App\Support\Elections\LiveResults;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * A department's front page: its open elections (to vote in) and the
 * results of past ones. Drafts stay with its admins.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request, Organization $organization): View
    {
        $open = $organization->elections()->where('status', ElectionStatus::Open)->orderBy('ends_at')->get();
        $past = $organization->elections()->where('status', ElectionStatus::Closed)->latest('closed_at')->limit(12)->get();

        $voter = $request->user('voter');
        $voted = $voter instanceof Voter ? DB::table('organization_election_voters')
            ->where('voter_id', $voter->id)
            ->whereIn('election_id', $open->pluck('id'))
            ->pluck('election_id')
            ->map(fn ($id) => (int) $id)
            ->flip()
            ->all() : [];

        return view('vote.home', [
            'open' => $open,
            'past' => $past,
            'voted' => $voted,
            'voter' => $voter instanceof Voter ? $voter : null,
            'drafts' => $voter instanceof Voter && $voter->isAdmin() ? $organization->elections()->where('status', ElectionStatus::Draft)->count() : 0,
            'results' => $open->concat($past)->mapWithKeys(fn (Election $election) => [$election->id => LiveResults::read($election)])->all(),
        ]);
    }
}
