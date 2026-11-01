<?php

namespace App\Models;

use App\Enums\Level;
use App\Enums\Programme;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One student on a department's nominal roll. Kept per class (programme
 * and level), like the NACOS roll but without courses (arms).
 *
 * @property int $id
 * @property int $organization_id
 * @property string $matric_number
 * @property string|null $fullname
 * @property string|null $email
 * @property Level|null $level
 * @property Programme|null $programme
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class OrganizationRollEntry extends Model
{
    protected $table = 'organization_roll';

    protected $fillable = ['organization_id', 'matric_number', 'fullname', 'email', 'level', 'programme'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'fullname' => null,
        'email' => null,
        'level' => null,
        'programme' => null,
    ];

    protected function casts(): array
    {
        return [
            'level' => Level::class,
            'programme' => Programme::class,
        ];
    }

    /** "ND1 Full-time"; empty when the class isn't known. */
    public function classLabel(): string
    {
        return trim(($this->level?->label() ?? '').' '.($this->programme?->label() ?? ''));
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
