<?php

namespace App\Support\Elections;

use App\Models\Election;
use App\Models\ElectionCandidate;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Casts a ballot: one per voter per election, every position on it, any
 * position may be skipped.
 *
 * Who voted goes into election_voters (its primary key makes a second
 * ballot impossible, even from two requests at once); what was voted goes
 * into election_votes with random ids and no voter or time. Both are
 * written in one transaction, so a ballot counts fully or not at all.
 *
 * Many ballots for the same candidates arrive at once near closing time.
 * Each ballot locks its candidates' rows first, always in id order, so
 * ballots wait their turn for a few milliseconds instead of deadlocking
 * (found by the load test, tests/load); a deadlock that still happens is
 * retried.
 */
class BallotBox
{
    /**
     * @param  array<int|string, mixed>  $choices  position id => candidate id (empty to skip)
     *
     * @throws BallotRejected
     */
    public static function cast(Election $election, User $user, array $choices): void
    {
        $picked = self::check($election, $user, $choices);

        try {
            DB::transaction(function () use ($election, $user, $picked) {
                // A shared lock lets ballots run side by side but makes
                // closing the election wait for the ones in progress, and
                // ballots after it see that it closed.
                $current = Election::query()->whereKey($election->id)->sharedLock()->firstOrFail();

                if (! $current->isAcceptingVotes()) {
                    throw new BallotRejected('Voting for this election has closed.');
                }

                // Before anything is inserted: the vote rows' foreign keys
                // would otherwise take shared locks on these rows that the
                // count updates below then have to upgrade.
                $candidates = array_values($picked);
                sort($candidates);
                ElectionCandidate::query()->whereKey($candidates)->orderBy('id')->lockForUpdate()->get(['id']);

                DB::table('election_voters')->insert([
                    'election_id' => $election->id,
                    'user_id' => $user->id,
                    'voted_at' => now(),
                ]);

                $rows = [];
                foreach ($picked as $positionId => $candidateId) {
                    // Random UUIDv4 ids: their order says nothing about when
                    // a vote was cast.
                    $rows[] = [
                        'id' => (string) Str::uuid(),
                        'election_id' => $election->id,
                        'position_id' => $positionId,
                        'candidate_id' => $candidateId,
                    ];
                }

                DB::table('election_votes')->insert($rows);

                foreach ($candidates as $candidateId) {
                    ElectionCandidate::query()->whereKey($candidateId)->increment('votes_count');
                }
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            throw new AlreadyVoted;
        }
    }

    /**
     * Checks the ballot against the election before anything is written:
     * every position belongs to this election and every candidate to that
     * position.
     *
     * @param  array<int|string, mixed>  $choices
     * @return array<int, int> position id => candidate id
     */
    private static function check(Election $election, User $user, array $choices): array
    {
        if (! $election->isAcceptingVotes()) {
            throw new BallotRejected('Voting for this election has closed.');
        }

        $reason = $election->ineligibilityReason($user);

        if ($reason !== null) {
            throw new BallotRejected($reason);
        }

        if ($election->hasVoted($user)) {
            throw new AlreadyVoted;
        }

        $positions = $election->positions()->with('candidates:id,position_id')->get(['id', 'election_id'])->keyBy('id');
        $picked = [];

        foreach ($choices as $positionId => $candidateId) {
            if ($candidateId === null || $candidateId === '') {
                continue;
            }

            $position = is_numeric($positionId) ? $positions->get((int) $positionId) : null;

            if ($position === null || ! is_numeric($candidateId) || ! $position->candidates->contains('id', (int) $candidateId)) {
                throw new BallotRejected('This ballot doesn\'t match the election. Reload the page and try again.');
            }

            $picked[$position->id] = (int) $candidateId;
        }

        if ($picked === []) {
            throw new BallotRejected('Choose a candidate for at least one position.');
        }

        return $picked;
    }
}
