<?php

namespace App\Models;

use App\Enums\Level;
use App\Enums\Programme;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Notifications\AccountInvitation as AccountInvitationNotification;
use App\Support\Classes\Arms;
use App\Support\Classes\SchoolClass;
use App\Support\MatricNumber;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $fullname
 * @property string|null $display_name
 * @property string $matric_number
 * @property int|null $entry_year
 * @property string|null $email
 * @property int $department_id
 * @property Level $level
 * @property Programme $programme
 * @property string|null $arm
 * @property Carbon|null $graduated_at
 * @property Role $role
 * @property UserStatus $status
 * @property bool $must_change_password
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $roll_email_verified_at
 * @property string|null $two_factor_secret
 * @property list<string>|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property int|null $two_factor_last_step
 * @property Carbon|null $last_login_at
 * @property string|null $last_login_ip
 * @property Carbon|null $vote_held_at
 * @property string|null $vote_hold_reason
 * @property int|null $vote_held_by
 * @property Carbon $created_at
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Role and status are deliberately not mass assignable: they may only be
     * changed through explicit admin actions.
     *
     * @var list<string>
     */
    /** A display name: letters, with spaces, hyphens, apostrophes and dots. */
    public const DISPLAY_NAME_PATTERN = "/^\\pL[\\pL\\pM .'’-]*$/u";

    protected $fillable = [
        'fullname',
        'display_name',
        'matric_number',
        'email',
        'department_id',
        'level',
        'programme',
        'password',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => 'student',
        'status' => 'active',
        'must_change_password' => false,
        // Nullable columns read on every page. Declared so a model that was
        // just created (not re-read from the database) has them too;
        // strict mode would otherwise throw.
        'display_name' => null,
        'arm' => null,
        'graduated_at' => null,
        'email_verified_at' => null,
        'roll_email_verified_at' => null,
        'two_factor_secret' => null,
        'two_factor_recovery_codes' => null,
        'two_factor_confirmed_at' => null,
        'two_factor_last_step' => null,
        'vote_held_at' => null,
        'vote_hold_reason' => null,
        'vote_held_by' => null,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'roll_email_verified_at' => 'datetime',
            'password' => 'hashed',
            'level' => Level::class,
            'programme' => Programme::class,
            'role' => Role::class,
            'status' => UserStatus::class,
            'must_change_password' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_step' => 'integer',
            'last_login_at' => 'datetime',
            'graduated_at' => 'datetime',
            'vote_held_at' => 'datetime',
        ];
    }

    /**
     * Normalise matric numbers so lookups are case-insensitive, and keep the
     * entry year and arm (HND SWD or NCC) it contains.
     *
     * @return Attribute<string, string>
     */
    protected function matricNumber(): Attribute
    {
        return Attribute::make(set: fn (string $value) => [
            'matric_number' => MatricNumber::normalize($value),
            'entry_year' => MatricNumber::entryYear($value),
            'arm' => Arms::fromMatric($value),
        ]);
    }

    /**
     * The role as people see it: a student who has graduated is a
     * "Graduate" (course reps, governors and admins keep their title).
     */
    public function roleLabel(): string
    {
        return $this->role === Role::Student && $this->isGraduated() ? 'Graduate' : $this->role->label();
    }

    /** Counted once per request: the header and the home page both show it. */
    private ?int $unreadCount = null;

    public function unreadNotificationCount(): int
    {
        return $this->unreadCount ??= $this->unreadNotifications()->count();
    }

    /**
     * The student's class: programme, level and arm. Null for graduates,
     * and when the school doesn't run that combination (an old account).
     */
    public function schoolClass(): ?SchoolClass
    {
        return $this->isGraduated() ? null : SchoolClass::make($this->programme, $this->level, $this->arm);
    }

    /**
     * Graduates keep their account to read the library, but can't vote or
     * upload, and have no class (timetable, exams).
     */
    public function isGraduated(): bool
    {
        return $this->graduated_at !== null;
    }

    /** "HND1 SWD Full-time", or level and programme when not a class. */
    public function classLabel(): string
    {
        return SchoolClass::make($this->programme, $this->level, $this->arm)?->label() ?? implode(' ', array_filter([$this->level->label(), Arms::short($this->arm), $this->programme->label()]));
    }

    public function firstName(): string
    {
        return explode(' ', trim($this->fullname))[0];
    }

    /**
     * What to call the student in greetings: the name they chose, or the
     * first word of their full name. Admin pages and records use the full
     * name.
     */
    public function greetingName(): string
    {
        return $this->display_name !== null && trim($this->display_name) !== '' ? $this->display_name : $this->firstName();
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null;
    }

    /**
     * Only a verified address may receive password reset links.
     */
    public function hasVerifiedEmailAddress(): bool
    {
        return $this->email !== null && $this->email_verified_at !== null;
    }

    /**
     * Proved they control the class-list email on the nominal roll by
     * opening the one-time link sent there (docs/vote-integrity.md). It is
     * the one address an impersonator can't set, so voting can require it.
     */
    public function hasVerifiedRollEmail(): bool
    {
        return $this->roll_email_verified_at !== null;
    }

    /**
     * Hasn't confirmed their class email yet, but could: their matric is on
     * the roll with an address to send the one-time link to. Drives the
     * site-wide "confirm your account to vote" nudge (docs/vote-integrity.md).
     */
    public function awaitingRollEmailVerification(): bool
    {
        return ! $this->hasVerifiedRollEmail() && RollEntry::emailFor($this->matric_number) !== null;
    }

    /**
     * Where this account's mail goes. Account-verification links must reach
     * the class-list email on the nominal roll, never an address an
     * impersonator may have set on the account (docs/vote-integrity.md);
     * everything else goes to the account's own address.
     */
    public function routeNotificationForMail(Notification $notification): ?string
    {
        if ($notification instanceof AccountInvitationNotification) {
            return RollEntry::emailFor($this->matric_number) ?? $this->email;
        }

        return $this->email;
    }

    /**
     * Laravel's verification mail is only sent when there is an address.
     */
    public function sendEmailVerificationNotification(): void
    {
        if ($this->email !== null) {
            parent::sendEmailVerificationNotification();
        }
    }

    public function hasRole(Role $role): bool
    {
        return $this->role->atLeast($role);
    }

    /**
     * On hold for voting: an admin wants to see the student in person
     * before this account can vote (docs/vote-integrity.md).
     */
    public function isOnVoteHold(): bool
    {
        return $this->vote_held_at !== null;
    }

    /**
     * The admin who put the account on hold.
     *
     * @return BelongsTo<User, $this>
     */
    public function voteHeldBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'vote_held_by');
    }

    public function isSuspended(): bool
    {
        return $this->status === UserStatus::Suspended;
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', UserStatus::Active);
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return HasMany<Book, $this>
     */
    public function uploads(): HasMany
    {
        return $this->hasMany(Book::class, 'uploader_id');
    }

    /**
     * @return BelongsToMany<Book, $this>
     */
    public function bookmarks(): BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'bookmarks')->withPivot('created_at');
    }

    /**
     * The invitation email, for accounts made from the nominal roll.
     *
     * @return HasOne<AccountInvitation, $this>
     */
    public function invitation(): HasOne
    {
        return $this->hasOne(AccountInvitation::class);
    }

    /**
     * @return HasMany<PasswordResetCode, $this>
     */
    public function passwordResetCodes(): HasMany
    {
        return $this->hasMany(PasswordResetCode::class);
    }

    /**
     * @return HasMany<ReadingProgress, $this>
     */
    public function readingProgress(): HasMany
    {
        return $this->hasMany(ReadingProgress::class);
    }
}
