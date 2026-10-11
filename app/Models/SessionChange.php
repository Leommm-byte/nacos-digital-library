<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One start of a new academic session, with what it changed so it can be
 * undone (App\Support\Sessions\NewSession).
 *
 * @property int $id
 * @property string|null $from_session
 * @property string $to_session
 * @property int|null $started_by
 * @property Carbon $started_at
 * @property Carbon|null $undone_at
 * @property bool $exams_were_published
 * @property string|null $roll
 * @property array{promoted: int, graduated: int, roll: int, lectures: int, exams: int}|null $summary
 * @property Carbon $created_at
 */
class SessionChange extends Model
{
    protected $fillable = ['from_session', 'to_session', 'started_at', 'exams_were_published', 'roll', 'summary'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'from_session' => null,
        'undone_at' => null,
        'roll' => null,
        'summary' => null,
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'undone_at' => 'datetime',
            'exams_were_published' => 'boolean',
            'summary' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }
}
