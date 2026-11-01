<?php

namespace App\Models;

use App\Enums\UserStatus;
use App\Enums\VoterRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A department NACOS runs elections for (docs/department-elections.md).
 * Its space is at /vote/{slug}: its own admins, nominal roll, voters and
 * elections, with "Powered by NACOS YabaTech" at the bottom. Only NACOS
 * admins create them; a paused department's space shows a notice instead.
 *
 * @property int $id
 * @property string $name
 * @property string|null $short_name
 * @property string $slug
 * @property UserStatus $status
 * @property string|null $contact_name
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property string|null $notes
 * @property string|null $logo_path
 * @property string|null $colour
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Organization extends Model
{
    protected $fillable = ['name', 'short_name', 'slug', 'contact_name', 'contact_email', 'contact_phone', 'notes', 'colour'];

    /** Addresses under /vote/ that pages of their own use. */
    public const RESERVED_SLUGS = ['apply'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
        'short_name' => null,
        'contact_name' => null,
        'contact_email' => null,
        'contact_phone' => null,
        'notes' => null,
        'logo_path' => null,
        'colour' => null,
    ];

    protected function casts(): array
    {
        return [
            'status' => UserStatus::class,
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * The department a /vote/{organization}/… URL names, whether or not
     * route binding has turned it into a model yet.
     */
    public static function fromRoute(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        return is_string($value) && $value !== '' ? static::query()->where('slug', $value)->first() : null;
    }

    /**
     * The department whose space the current request is in (set by the
     * "vote" middleware), or null elsewhere.
     */
    public static function current(): ?self
    {
        if (app()->bound(self::class)) {
            $organization = app(self::class);

            return $organization->exists ? $organization : null;
        }

        return self::fromRoute(request()->route()?->parameter('organization'));
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    /** The short name where there is one ("FST"), for tight spaces. */
    public function shortLabel(): string
    {
        return $this->short_name ?: $this->name;
    }

    /**
     * Its elections. Election's default scope keeps them out of the NACOS
     * app; this is the way in.
     *
     * @return HasMany<Election, $this>
     */
    public function elections(): HasMany
    {
        return $this->hasMany(Election::class)->withoutGlobalScope('nacos');
    }

    /**
     * @return HasMany<Voter, $this>
     */
    public function voters(): HasMany
    {
        return $this->hasMany(Voter::class);
    }

    /**
     * @return HasMany<Voter, $this>
     */
    public function admins(): HasMany
    {
        return $this->hasMany(Voter::class)->where('role', VoterRole::Admin);
    }

    /**
     * @return HasMany<OrganizationRollEntry, $this>
     */
    public function roll(): HasMany
    {
        return $this->hasMany(OrganizationRollEntry::class);
    }
}
