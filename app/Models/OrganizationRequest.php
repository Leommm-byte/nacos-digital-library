<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A department asking NACOS for an election space (/vote/apply). A NACOS
 * admin approves it (Admin → Departments, which sets the department up)
 * or declines it.
 *
 * @property int $id
 * @property string $department_name
 * @property string $contact_name
 * @property string|null $contact_role
 * @property string $contact_email
 * @property string|null $contact_phone
 * @property string|null $message
 * @property string $status
 * @property int|null $organization_id
 * @property string|null $ip_address
 * @property Carbon $created_at
 */
class OrganizationRequest extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const DECLINED = 'declined';

    protected $fillable = ['department_name', 'contact_name', 'contact_role', 'contact_email', 'contact_phone', 'message'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => self::PENDING,
        'contact_role' => null,
        'contact_phone' => null,
        'message' => null,
        'organization_id' => null,
        'ip_address' => null,
    ];

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
