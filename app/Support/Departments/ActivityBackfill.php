<?php

namespace App\Support\Departments;

use Illuminate\Support\Facades\DB;

/**
 * Fills departments' activity logs with what happened before the log
 * existed, from those records' own dates: accounts registered, voting
 * opened, ballots cast (who, never how) and voting closed. Each entry is
 * marked as recorded later and has no address. Run once, by the migration
 * that made the log.
 */
class ActivityBackfill
{
    public static function run(): void
    {
        $later = json_encode(['recorded_later' => true]);

        DB::table('voters')->where('role', 'voter')->orderBy('id')->chunkById(500, function ($voters) use ($later) {
            DB::table('organization_activity')->insert($voters->map(fn ($voter) => [
                'organization_id' => $voter->organization_id,
                'voter_id' => $voter->id,
                'actor_type' => 'voter',
                'actor_name' => $voter->fullname,
                'actor_login' => $voter->matric_number ?? $voter->email,
                'action' => 'voter_registered',
                'subject_type' => 'Voter',
                'subject_id' => $voter->id,
                'meta' => $later,
                'created_at' => $voter->created_at,
            ])->all());
        });

        $elections = DB::table('elections')->whereNotNull('organization_id')->get(['id', 'organization_id', 'title', 'starts_at', 'closed_at', 'status']);

        foreach ($elections as $election) {
            $meta = json_encode(['title' => $election->title, 'recorded_later' => true]);

            if ($election->status !== 'draft' && $election->starts_at !== null) {
                DB::table('organization_activity')->insert([
                    'organization_id' => $election->organization_id,
                    'action' => 'election_launched',
                    'subject_type' => 'Election',
                    'subject_id' => $election->id,
                    'meta' => $meta,
                    'created_at' => $election->starts_at,
                ]);
            }

            DB::table('organization_election_voters')
                ->join('voters', 'voters.id', '=', 'organization_election_voters.voter_id')
                ->where('organization_election_voters.election_id', $election->id)
                ->orderBy('organization_election_voters.voter_id')
                ->select(['organization_election_voters.voter_id', 'organization_election_voters.voted_at', 'voters.fullname', 'voters.matric_number', 'voters.email'])
                ->chunk(500, function ($ballots) use ($election, $meta) {
                    DB::table('organization_activity')->insert($ballots->map(fn ($ballot) => [
                        'organization_id' => $election->organization_id,
                        'voter_id' => $ballot->voter_id,
                        'actor_type' => 'voter',
                        'actor_name' => $ballot->fullname,
                        'actor_login' => $ballot->matric_number ?? $ballot->email,
                        'action' => 'election_voted',
                        'subject_type' => 'Election',
                        'subject_id' => $election->id,
                        'meta' => $meta,
                        'created_at' => $ballot->voted_at,
                    ])->all());
                });

            if ($election->closed_at !== null) {
                DB::table('organization_activity')->insert([
                    'organization_id' => $election->organization_id,
                    'action' => 'election_closed',
                    'subject_type' => 'Election',
                    'subject_id' => $election->id,
                    'meta' => $meta,
                    'created_at' => $election->closed_at,
                ]);
            }
        }
    }
}
