<?php

namespace App\Support\Elections;

use App\Enums\ElectionStatus;
use App\Models\Election;
use App\Models\ElectionCandidate;
use App\Models\ElectionPosition;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Public results for open and closed elections, as a small static JSON file
 * (public/live/election-{id}.json). The web server serves it with an ETag,
 * so the election page's polling costs a 304 and no PHP at all.
 *
 * The file is rewritten after every ballot (decided with the owner: voters
 * should see their vote counted), and closing the election publishes the
 * final count.
 *
 * The page itself is rendered from the same file, never from live counts.
 */
class LiveResults
{
    /**
     * Rewrites the file with the current totals. Returns whether it was
     * written.
     */
    public static function publish(Election $election): bool
    {
        if ($election->status === ElectionStatus::Draft) {
            return false;
        }

        // One writer at a time; a ballot waits briefly for the one before,
        // so the last ballot always ends up in the file.
        try {
            return (bool) Cache::lock("elections:{$election->id}:publish", 10)->block(5, fn () => self::write($election));
        } catch (LockTimeoutException) {
            // Another writer is stuck; the next ballot or the scheduler
            // catches up.
            return false;
        }
    }

    /**
     * The published results, publishing them first if the file is missing.
     *
     * @return array<string, mixed>|null
     */
    public static function read(Election $election): ?array
    {
        if ($election->status === ElectionStatus::Draft) {
            return null;
        }

        $data = self::load($election);

        if ($data === null && self::publish($election)) {
            $data = self::load($election);
        }

        return $data;
    }

    public static function forget(Election $election): void
    {
        @unlink(self::path($election));
    }

    public static function path(Election $election): string
    {
        return rtrim((string) config('elections.live_path'), '/')."/election-{$election->id}.json";
    }

    public static function url(Election $election): string
    {
        return asset("live/election-{$election->id}.json");
    }

    /**
     * @return array<string, mixed>
     */
    public static function build(Election $election): array
    {
        // One transaction, so the ballot count and the totals are read from
        // the same moment.
        return DB::transaction(function () use ($election) {
            $ballots = $election->ballotCount();
            $electorate = $election->electorate()->count();

            $positions = $election->positions()->with('candidates')->get()->map(function (ElectionPosition $position) use ($ballots) {
                $total = (int) $position->candidates->sum('votes_count');
                $top = (int) $position->candidates->max('votes_count');
                $leaders = $position->candidates->where('votes_count', $top)->count();

                return [
                    'id' => $position->id,
                    'title' => $position->title,
                    'votes' => $total,
                    'skipped' => max(0, $ballots - $total),
                    'candidates' => $position->candidates->map(fn (ElectionCandidate $candidate) => [
                        'id' => $candidate->id,
                        'name' => $candidate->name,
                        'votes' => $candidate->votes_count,
                        'percent' => $total > 0 ? round($candidate->votes_count / $total * 100, 1) : 0.0,
                        // Ahead (or the winner, once closed); ties are marked.
                        'leading' => $top > 0 && $candidate->votes_count === $top,
                        'tied' => $top > 0 && $candidate->votes_count === $top && $leaders > 1,
                    ])->values()->all(),
                ];
            })->values()->all();

            return [
                'election' => $election->id,
                // Tells this election's file apart from one left behind by
                // an earlier database with the same ids.
                'key' => self::key($election),
                'status' => $election->hasEnded() ? 'closed' : 'open',
                'final' => $election->status === ElectionStatus::Closed,
                'ends_at' => $election->ends_at?->toIso8601String(),
                'ballots' => $ballots,
                'electorate' => $electorate,
                'turnout' => $electorate > 0 ? round(min(100, $ballots / $electorate * 100), 1) : 0.0,
                'updated_at' => now()->toIso8601String(),
                'positions' => $positions,
            ];
        });
    }

    private static function write(Election $election): bool
    {
        $path = self::path($election);
        $directory = dirname($path);

        if (! is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }

        $json = json_encode(self::build($election), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        // Write next to it and rename, so a poll never reads half a file.
        $temporary = $path.'.'.bin2hex(random_bytes(4)).'.tmp';

        if (@file_put_contents($temporary, $json) === false) {
            report(new RuntimeException("Could not write election results to {$directory}."));

            return false;
        }

        @chmod($temporary, 0644);

        return rename($temporary, $path);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function load(Election $election): ?array
    {
        $path = self::path($election);

        if (! is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) && ($data['key'] ?? null) === self::key($election) ? $data : null;
    }

    private static function key(Election $election): string
    {
        return substr(hash('sha256', $election->id.'|'.$election->created_at->getTimestamp().'|'.config('app.key')), 0, 16);
    }
}
