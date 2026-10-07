<?php

namespace App\Support\Elections;

use App\Enums\ElectionStatus;
use App\Models\Election;
use App\Models\RollEntry;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Launching and closing elections. An election only moves forward: a
 * closed election can't be reopened (start a new one instead).
 */
class ElectionLifecycle
{
    /**
     * Why the election can't be launched yet, or null when it's ready.
     */
    public static function launchProblem(Election $election): ?string
    {
        if ($election->roll_only && ! RollEntry::query()->exists()) {
            return 'The nominal roll is empty. Import it first, or untick "Only students on the nominal roll".';
        }

        $positions = $election->positions()->withCount('candidates')->get();

        if ($positions->isEmpty()) {
            return 'Add at least one position before launching.';
        }

        $empty = $positions->firstWhere('candidates_count', 0);

        if ($empty !== null) {
            return "Add a candidate for {$empty->title}, or remove that position.";
        }

        return null;
    }

    /**
     * Opens voting now, for the given number of hours.
     */
    public static function launch(Election $election, int $hours): void
    {
        $election->status = ElectionStatus::Open;
        $election->starts_at = now();
        $election->ends_at = now()->addHours($hours);
        $election->save();

        LiveResults::publish($election, true);
        Audit::record('election_launched', $election, ['title' => $election->title, 'hours' => $hours]);
    }

    /**
     * Closes an open election (early, by an admin, or by the scheduler at
     * its end time) and publishes the final results. Returns false if it
     * wasn't open.
     */
    public static function close(Election $election, bool $automatic = false): bool
    {
        $closed = DB::transaction(function () use ($election) {
            // Waits for ballots in progress (they hold a shared lock).
            $current = Election::query()->whereKey($election->id)->lockForUpdate()->first();

            if ($current === null || $current->status !== ElectionStatus::Open) {
                return false;
            }

            $now = now();
            $current->status = ElectionStatus::Closed;
            $current->closed_at = $now;

            if ($current->ends_at === null || $current->ends_at->gt($now)) {
                $current->ends_at = $now;
            }

            $current->save();

            return $current;
        });

        if ($closed === false) {
            return false;
        }

        $election->setRawAttributes($closed->getAttributes(), true);

        LiveResults::publish($election, true);
        Audit::record('election_closed', $election, [
            'title' => $election->title,
            'automatic' => $automatic,
            'ballots' => $election->ballotCount(),
        ]);

        return true;
    }

    /**
     * Closes every open election whose time is up. Run by the scheduler
     * every minute; returns how many were closed.
     */
    public static function closeDue(): int
    {
        $closed = 0;

        Election::query()
            ->where('status', ElectionStatus::Open)
            ->where('ends_at', '<=', now())
            ->get()
            ->each(function (Election $election) use (&$closed) {
                $closed += self::close($election, true) ? 1 : 0;
            });

        return $closed;
    }
}
