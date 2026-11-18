<?php

namespace App\Support\Elections;

use App\Enums\ElectionStatus;
use App\Models\Election;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\Cache;

/**
 * Putting accounts on hold for voting, and releasing them
 * (docs/vote-integrity.md). A held account can sign in as usual but can't
 * vote until an admin has checked the student in person and released it;
 * released during an election, it can vote at once. Each change is in the
 * audit log, with who made it.
 */
class VoteHolds
{
    public static function hold(User $user, string $reason): bool
    {
        if ($user->isOnVoteHold()) {
            return false;
        }

        $user->forceFill([
            'vote_held_at' => now(),
            'vote_hold_reason' => $reason,
            'vote_held_by' => auth('web')->id(),
        ])->save();

        Audit::record('vote_hold_placed', $user, ['name' => $user->fullname, 'matric' => $user->matric_number, 'reason' => $reason]);

        return true;
    }

    public static function release(User $user, string $note): bool
    {
        if (! $user->isOnVoteHold()) {
            return false;
        }

        $reason = $user->vote_hold_reason;
        $user->forceFill(['vote_held_at' => null, 'vote_hold_reason' => null, 'vote_held_by' => null])->save();

        Audit::record('vote_hold_released', $user, ['name' => $user->fullname, 'matric' => $user->matric_number, 'reason' => $reason, 'note' => $note]);

        return true;
    }

    /**
     * Open elections count their electorate once a minute; after holds
     * change, count again now and update the live results.
     */
    public static function recount(): void
    {
        Election::query()->where('status', ElectionStatus::Open)->get()->each(function (Election $election) {
            Cache::forget("elections:{$election->id}:electorate");
            Cache::forget("elections:{$election->id}:held");
            LiveResults::refresh($election);
        });
    }
}
