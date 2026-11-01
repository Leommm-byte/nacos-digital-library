<?php

namespace App\Models;

use App\Enums\UserStatus;
use App\Enums\VoterRole;
use App\Support\MatricNumber;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;

/**
 * An account in one department's election space (docs/department-elections.md):
 * a student who votes, or one of the department's admins. Signs in through
 * the "voter" guard, never into the NACOS app; a student in two
 * departments' elections has an account in each.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $fullname
 * @property string|null $matric_number
 * @property int|null $entry_year
 * @property string|null $email
 * @property VoterRole $role
 * @property UserStatus $status
 * @property bool $must_change_password
 * @property Carbon|null $last_login_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Organization $organization
 */
class Voter extends Authenticatable
{
    protected $fillable = ['organization_id', 'fullname', 'matric_number', 'email', 'password'];

    /**
     * @var list<string>
     */
    protected $hidden = ['password', 'remember_token'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => 'voter',
        'status' => 'active',
        'must_change_password' => false,
        'matric_number' => null,
        'entry_year' => null,
        'email' => null,
        'last_login_at' => null,
        'remember_token' => null,
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => VoterRole::class,
            'status' => UserStatus::class,
            'must_change_password' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * Matric numbers are stored normalised, with the entry year they hold.
     *
     * @return Attribute<string|null, string|null>
     */
    protected function matricNumber(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => [
            'matric_number' => $value === null || trim($value) === '' ? null : MatricNumber::normalize($value),
            'entry_year' => $value === null || trim($value) === '' ? null : MatricNumber::entryYear($value),
        ]);
    }

    /**
     * Emails are compared without case.
     *
     * @return Attribute<string|null, string|null>
     */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => $value === null || trim($value) === '' ? null : strtolower(trim($value)));
    }

    public function isAdmin(): bool
    {
        return $this->role === VoterRole::Admin;
    }

    public function isSuspended(): bool
    {
        return $this->status === UserStatus::Suspended;
    }

    public function firstName(): string
    {
        return explode(' ', trim($this->fullname))[0];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
