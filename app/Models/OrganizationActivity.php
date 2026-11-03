<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One entry in a department's activity log, append-only. Write through
 * App\Support\Departments\Activity.
 *
 * @property int $id
 * @property int $organization_id
 * @property int|null $voter_id
 * @property int|null $user_id
 * @property string|null $actor_type voter, nacos, or null for the system
 * @property string|null $actor_name
 * @property string|null $actor_login
 * @property string $action
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property array<string, mixed>|null $meta
 * @property Carbon $created_at
 */
class OrganizationActivity extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'organization_activity';

    protected $guarded = ['id'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'voter_id' => null,
        'user_id' => null,
        'actor_type' => null,
        'actor_name' => null,
        'actor_login' => null,
        'subject_type' => null,
        'subject_id' => null,
        'ip_address' => null,
        'user_agent' => null,
        'meta' => null,
    ];

    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Done by a NACOS admin, not one of the department's accounts. */
    public function byNacos(): bool
    {
        return $this->actor_type === 'nacos';
    }
}
