<?php

namespace App\Models;

use App\Enums\ElectionStatus;
use App\Enums\Level;
use App\Enums\Programme;
use App\Enums\UserStatus;
use App\Support\Classes\Arms;
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
 * Eligibility: every active account, optionally narrowed to students on
 * the nominal roll (the official list an admin imports), to some
 * programmes and levels, and to a range of entry years taken from the
 * matric number. The roll's class (programme and level) counts instead of
 * the one the student chose at signup.
 *
 * @property int $id
 * @property int|null $organization_id
 * @property string $title
 * @property string|null $description
 * @property list<string>|null $levels
 * @property list<string>|null $programmes
 * @property list<string>|null $arms
 * @property int|null $entry_year_from
 * @property int|null $entry_year_to
 * @property bool $roll_only
 * @property bool $allow_late_accounts
 * @property ElectionStatus $status
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property Carbon|null $closed_at
 * @property int|null $created_by
 * @property Carbon $created_at
 * @property-read Organization|null $organization
 */
class Election extends Model
{
    /** @use HasFactory<ElectionFactory> */
    use HasFactory;

    /**
     * NACOS's own elections everywhere by default: a department's elections
     * (docs/department-elections.md) only appear through its Organization.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('nacos', fn (Builder $query) => $query->whereNull('elections.organization_id'));
    }

    /**
     * Every election, NACOS's and the departments': for the scheduler and
     * the live results, which serve them all.
     *
     * @return Builder<static>
     */
    public static function everywhere(): Builder
    {
        return static::query()->withoutGlobalScope('nacos');
    }

    /**
     * On a department's pages (/vote/{organization}/…) only that
     * department's elections are found; elsewhere only NACOS's.
     */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        $route = request()->route();
        $organization = $route !== null && in_array('organization', $route->parameterNames(), true)
            ? Organization::current()
            : null;

        if ($route !== null && in_array('organization', $route->parameterNames(), true) && $organization === null) {
            return null;
        }

        $query = $organization !== null ? $organization->elections() : static::query();

        return $query->where($field ?? $this->getRouteKeyName(), $value)->first();
    }

    public function isForDepartment(): bool
    {
        return $this->organization_id !== null;
    }

    /** Voting windows an admin can launch an election for, in hours. */
    public const DURATIONS = [1, 2, 6, 12, 24, 48, 72];

    protected $fillable = ['title', 'description', 'levels', 'programmes', 'arms', 'entry_year_from', 'entry_year_to', 'roll_only', 'allow_late_accounts'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'description' => null,
        'levels' => null,
        'programmes' => null,
        'arms' => null,
        'entry_year_from' => null,
        'entry_year_to' => null,
        'organization_id' => null,
        'roll_only' => true,
        'allow_late_accounts' => false,
        'starts_at' => null,
        'ends_at' => null,
        'closed_at' => null,
    ];

    protected function casts(): array
    {
        return [
            'status' => ElectionStatus::class,
            'levels' => 'array',
            'programmes' => 'array',
            'arms' => 'array',
            'entry_year_from' => 'integer',
            'entry_year_to' => 'integer',
            'roll_only' => 'boolean',
            'allow_late_accounts' => 'boolean',
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
     * @return list<Programme>
     */
    public function programmeList(): array
    {
        return array_values(array_filter(array_map(fn (string $programme) => Programme::tryFrom($programme), $this->programmes ?? [])));
    }

    /**
     * The arms (courses) the election is limited to, that still exist.
     *
     * @return list<string>
     */
    public function armList(): array
    {
        return array_values(array_filter($this->arms ?? [], fn (string $arm) => Arms::exists($arm)));
    }

    /**
     * Who may vote, in a short sentence: "ND1 and ND2 students who entered
     * from 2024 to 2026".
     */
    public function eligibilitySummary(): string
    {
        $levels = array_map(fn (Level $level) => $level->label(), $this->levelList());
        $programmes = array_map(fn (Programme $programme) => $programme->label(), $this->programmeList());
        $arms = array_map(fn (string $arm) => Arms::short($arm), $this->armList());
        $who = $levels === [] && $programmes === [] && $arms === []
            ? 'All students'
            : implode(' ', array_filter([
                $programmes !== [] ? $this->joinWords($programmes) : null,
                $levels !== [] ? $this->joinWords($levels) : null,
                $arms !== [] ? $this->joinWords($arms) : null,
                'students',
            ]));

        $from = $this->entry_year_from;
        $to = $this->entry_year_to;

        $years = match (true) {
            $from !== null && $to !== null && $from === $to => " who entered in {$from}",
            $from !== null && $to !== null => " who entered from {$from} to {$to}",
            $from !== null => " who entered in {$from} or later",
            $to !== null => " who entered in {$to} or earlier",
            default => '',
        };

        return $who.$years.($this->roll_only ? ', on the nominal roll' : '');
    }

    /**
     * Null when the user may vote in this election, otherwise why not.
     */
    public function ineligibilityReason(User|Voter $user): ?string
    {
        if ($user instanceof Voter || $this->isForDepartment()) {
            return $this->departmentIneligibilityReason($user);
        }

        if ($user->status !== UserStatus::Active) {
            return 'Your account isn\'t active.';
        }

        if ($user->isGraduated()) {
            return 'You\'ve graduated, so you can\'t vote in student elections.';
        }

        if ($this->excludesLateAccounts() && $user->created_at->gt($this->starts_at)) {
            return 'Your account was created after voting opened, so it can\'t vote in this election. Only accounts that existed when voting opened can vote.';
        }

        $level = $user->level;
        $programme = $user->programme;
        $arm = $user->arm;

        if ($this->roll_only) {
            $entry = RollEntry::query()->where('matric_number', $user->matric_number)->first(['level', 'programme', 'arm']);

            if ($entry === null) {
                return 'Your matric number isn\'t on the current nominal roll, so you can\'t vote in this election. If that\'s a mistake, ask an admin to check the roll.';
            }

            $level = $entry->level ?? $level;
            $programme = $entry->programme ?? $programme;
            $arm = $entry->arm ?? $arm;
        }

        $programmes = $this->programmes ?? [];

        if ($programmes !== [] && ! in_array($programme->value, $programmes, true)) {
            return 'This election is for '.$this->eligibilitySummary().'.';
        }

        $levels = $this->levels ?? [];

        if ($levels !== [] && ! in_array($level->value, $levels, true)) {
            return 'This election is for '.$this->eligibilitySummary().'.';
        }

        $arms = $this->arms ?? [];

        if ($arms !== [] && ! in_array($arm, $arms, true)) {
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

    /**
     * Whether accounts created after voting opened are left out (the
     * default): otherwise someone could sign up with an unregistered
     * classmate's matric number from the roll and vote as them.
     *
     * @phpstan-assert-if-true !null $this->starts_at
     */
    public function excludesLateAccounts(): bool
    {
        return ! $this->allow_late_accounts && $this->starts_at !== null;
    }

    /**
     * A department's elections: its own active voters on its roll, created
     * before voting opened (unless late accounts are allowed), with the
     * roll's class and the matric number's entry year.
     */
    private function departmentIneligibilityReason(User|Voter $voter): ?string
    {
        if (! $voter instanceof Voter || $voter->organization_id !== $this->organization_id) {
            return 'This election is for another department\'s voters.';
        }

        if ($voter->status !== UserStatus::Active) {
            return 'Your account isn\'t active.';
        }

        if ($this->excludesLateAccounts() && $voter->created_at->gt($this->starts_at)) {
            return 'Your account was created after voting opened, so it can\'t vote in this election. Only accounts that existed when voting opened can vote.';
        }

        $entry = $voter->matric_number === null ? null : OrganizationRollEntry::query()
            ->where('organization_id', $this->organization_id)
            ->where('matric_number', $voter->matric_number)
            ->first(['level', 'programme']);

        if ($entry === null) {
            return 'Your matric number isn\'t on the department\'s nominal roll, so you can\'t vote. If that\'s a mistake, ask the department\'s election admin to check the roll.';
        }

        $outside = (($this->programmes ?? []) !== [] && ! in_array($entry->programme?->value, $this->programmes, true))
            || (($this->levels ?? []) !== [] && ! in_array($entry->level?->value, $this->levels, true))
            || ($this->entry_year_from !== null && ($voter->entry_year === null || $voter->entry_year < $this->entry_year_from))
            || ($this->entry_year_to !== null && ($voter->entry_year === null || $voter->entry_year > $this->entry_year_to));

        return $outside ? 'This election is for '.$this->eligibilitySummary().'.' : null;
    }

    public function isEligible(User|Voter $user): bool
    {
        return $this->ineligibilityReason($user) === null;
    }

    /**
     * Everyone who may vote, as a query (for turnout).
     *
     * @return Builder<User>|Builder<Voter>
     */
    public function electorate(): Builder
    {
        if ($this->isForDepartment()) {
            return $this->departmentElectorate();
        }

        $query = User::query()->where('users.status', UserStatus::Active)->whereNull('users.graduated_at');

        if ($this->excludesLateAccounts()) {
            $query->where('users.created_at', '<=', $this->starts_at);
        }
        $level = 'users.level';
        $programme = 'users.programme';
        $arm = 'users.arm';

        if ($this->roll_only) {
            $query->join('nominal_roll', 'nominal_roll.matric_number', '=', 'users.matric_number');
            $level = DB::raw('coalesce(nominal_roll.level, users.level)');
            $programme = DB::raw('coalesce(nominal_roll.programme, users.programme)');
            $arm = DB::raw('coalesce(nominal_roll.arm, users.arm)');
        }

        if (($this->programmes ?? []) !== []) {
            $query->whereIn($programme, $this->programmes);
        }

        if (($this->levels ?? []) !== []) {
            $query->whereIn($level, $this->levels);
        }

        if (($this->arms ?? []) !== []) {
            $query->whereIn($arm, $this->arms);
        }

        if ($this->entry_year_from !== null) {
            $query->where('users.entry_year', '>=', $this->entry_year_from);
        }

        if ($this->entry_year_to !== null) {
            $query->where('users.entry_year', '<=', $this->entry_year_to);
        }

        return $query;
    }

    /**
     * A department's electorate: its active voters on its roll.
     *
     * @return Builder<Voter>
     */
    private function departmentElectorate(): Builder
    {
        $query = Voter::query()
            ->where('voters.organization_id', $this->organization_id)
            ->where('voters.status', UserStatus::Active)
            ->join('organization_roll', function ($join) {
                $join->on('organization_roll.matric_number', '=', 'voters.matric_number')
                    ->on('organization_roll.organization_id', '=', 'voters.organization_id');
            });

        if ($this->excludesLateAccounts()) {
            $query->where('voters.created_at', '<=', $this->starts_at);
        }

        if (($this->programmes ?? []) !== []) {
            $query->whereIn('organization_roll.programme', $this->programmes);
        }

        if (($this->levels ?? []) !== []) {
            $query->whereIn('organization_roll.level', $this->levels);
        }

        if ($this->entry_year_from !== null) {
            $query->where('voters.entry_year', '>=', $this->entry_year_from);
        }

        if ($this->entry_year_to !== null) {
            $query->where('voters.entry_year', '<=', $this->entry_year_to);
        }

        return $query;
    }

    public function hasVoted(User|Voter $user): bool
    {
        if ($user instanceof Voter) {
            return DB::table('organization_election_voters')
                ->where('election_id', $this->id)
                ->where('voter_id', $user->id)
                ->exists();
        }

        return DB::table('election_voters')
            ->where('election_id', $this->id)
            ->where('user_id', $user->id)
            ->exists();
    }

    public function ballotCount(): int
    {
        return DB::table($this->votersTable())->where('election_id', $this->id)->count();
    }

    /** Where this election records who voted (never what they chose). */
    public function votersTable(): string
    {
        return $this->isForDepartment() ? 'organization_election_voters' : 'election_voters';
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
     * A department's voters who have cast a ballot (never what they chose).
     *
     * @return BelongsToMany<Voter, $this>
     */
    public function departmentVoters(): BelongsToMany
    {
        return $this->belongsToMany(Voter::class, 'organization_election_voters')->withPivot('voted_at');
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
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
