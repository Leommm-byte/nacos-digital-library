<?php

namespace App\Models;

use App\Enums\ElectionStatus;
use App\Enums\Level;
use App\Enums\UserStatus;
use Database\Factories\ElectionFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * An election moves one way only: draft (set up by an admin) → open (voting
 * for a fixed time) → closed. Positions, candidates and eligibility can only
 * change while it's a draft.
 *
 * Eligibility: every active account, optionally narrowed to some levels
 * and to a range of entry years taken from the matric number.
 *
 * @property int $id
 * @property string $title
 * @property string|null $description
 * @property list<string>|null $levels
 * @property int|null $entry_year_from
 * @property int|null $entry_year_to
 * @property ElectionStatus $status
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property Carbon|null $closed_at
 * @property int|null $created_by
 * @property Carbon $created_at
 */
class Election extends Model
{
    /** @use HasFactory<ElectionFactory> */
    use HasFactory;

    /** Voting windows an admin can launch an election for, in hours. */
    public const DURATIONS = [1, 2, 6, 12, 24, 48, 72];

    protected $fillable = ['title', 'description', 'levels', 'entry_year_from', 'entry_year_to'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'description' => null,
        'levels' => null,
        'entry_year_from' => null,
        'entry_year_to' => null,
        'starts_at' => null,
        'ends_at' => null,
        'closed_at' => null,
    ];

    protected function casts(): array
    {
        return [
            'status' => ElectionStatus::class,
            'levels' => 'array',
            'entry_year_from' => 'integer',
            'entry_year_to' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === ElectionStatus::Draft;
    }

    /**
     * Whether ballots are accepted right now (open and inside its window).
     * The scheduler closes elections at their end time; until it runs, the
     * end time alone already stops voting.
     */
    public function isAcceptingVotes(): bool
    {
        if ($this->status !== ElectionStatus::Open) {
            return false;
        }

        $now = now();

        return ($this->starts_at === null || $this->starts_at->lte($now))
            && ($this->ends_at === null || $this->ends_at->gt($now));
    }

    /**
     * Open but past its end time, waiting for the scheduler to close it.
     */
    public function hasEnded(): bool
    {
        return $this->status === ElectionStatus::Closed
            || ($this->status === ElectionStatus::Open && $this->ends_at !== null && $this->ends_at->lte(now()));
    }

    /**
     * @return list<Level>
     */
    public function levelList(): array
    {
        return array_values(array_filter(array_map(fn (string $level) => Level::tryFrom($level), $this->levels ?? [])));
    }

    /**
     * Who may vote, in a short sentence: "ND1 and ND2 students who entered
     * from 2024 to 2026".
     */
    public function eligibilitySummary(): string
    {
        $levels = array_map(fn (Level $level) => $level->label(), $this->levelList());
        $who = $levels === [] ? 'All students' : $this->joinWords($levels).' students';

        $from = $this->entry_year_from;
        $to = $this->entry_year_to;

        $years = match (true) {
            $from !== null && $to !== null && $from === $to => " who entered in {$from}",
            $from !== null && $to !== null => " who entered from {$from} to {$to}",
            $from !== null => " who entered in {$from} or later",
            $to !== null => " who entered in {$to} or earlier",
            default => '',
        };

        return $who.$years;
    }

    /**
     * Null when the user may vote in this election, otherwise why not.
     */
    public function ineligibilityReason(User $user): ?string
    {
        if ($user->status !== UserStatus::Active) {
            return 'Your account isn\'t active.';
        }

        $levels = $this->levels ?? [];

        if ($levels !== [] && ! in_array($user->level->value, $levels, true)) {
            return 'This election is for '.$this->eligibilitySummary().'.';
        }

        if ($this->entry_year_from !== null || $this->entry_year_to !== null) {
            $year = $user->entry_year;

            if ($year === null
                || ($this->entry_year_from !== null && $year < $this->entry_year_from)
                || ($this->entry_year_to !== null && $year > $this->entry_year_to)) {
                return 'This election is for '.$this->eligibilitySummary().'.';
            }
        }

        return null;
    }

    public function isEligible(User $user): bool
    {
        return $this->ineligibilityReason($user) === null;
    }

    /**
     * Everyone who may vote, as a query (for turnout).
     *
     * @return Builder<User>
     */
    public function electorate(): Builder
    {
        $query = User::query()->where('status', UserStatus::Active);

        if (($this->levels ?? []) !== []) {
            $query->whereIn('level', $this->levels);
        }

        if ($this->entry_year_from !== null) {
            $query->where('entry_year', '>=', $this->entry_year_from);
        }

        if ($this->entry_year_to !== null) {
            $query->where('entry_year', '<=', $this->entry_year_to);
        }

        return $query;
    }

    public function hasVoted(User $user): bool
    {
        return DB::table('election_voters')
            ->where('election_id', $this->id)
            ->where('user_id', $user->id)
            ->exists();
    }

    public function ballotCount(): int
    {
        return DB::table('election_voters')->where('election_id', $this->id)->count();
    }

    /**
     * Elections students can see (drafts stay with the admins).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function visible(Builder $query): void
    {
        $query->where('status', '!=', ElectionStatus::Draft);
    }

    /**
     * @return HasMany<ElectionPosition, $this>
     */
    public function positions(): HasMany
    {
        return $this->hasMany(ElectionPosition::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Users who have cast a ballot (never what they voted for).
     *
     * @return BelongsToMany<User, $this>
     */
    public function voters(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'election_voters')->withPivot('voted_at');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param  list<string>  $words
     */
    private function joinWords(array $words): string
    {
        if (count($words) <= 1) {
            return implode('', $words);
        }

        $last = array_pop($words);

        return implode(', ', $words).' and '.$last;
    }
}
