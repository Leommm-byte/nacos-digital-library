<?php

namespace App\Support\Elections;

use App\Models\RollEntry;
use App\Models\User;

/**
 * Votes in someone else's name (docs/vote-integrity.md).
 */
class RollEmailVerification
{
    /**
     * Marks accounts that have already proved their class email as
     * verified, so the vote gate and the advance send leave them alone: a
     * confirmed account email that matches the class-list email on the roll
     * can only have been set by opening the link sent there. Idempotent.
     */
    public static function backfill(): int
    {
        $count = 0;

        User::query()
            ->whereNull('roll_email_verified_at')
            ->whereNotNull('email_verified_at')
            ->whereNotNull('email')
            ->chunkById(500, function ($users) use (&$count) {
                foreach ($users as $user) {
                    $rollEmail = RollEntry::emailFor($user->matric_number);

                    if ($rollEmail !== null && strcasecmp($rollEmail, (string) $user->email) === 0) {
                        $user->forceFill(['roll_email_verified_at' => $user->email_verified_at])->saveQuietly();
                        $count++;
                    }
                }
            });

        return $count;
    }
}
