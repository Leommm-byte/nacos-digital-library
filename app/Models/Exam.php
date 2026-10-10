<?php

namespace App\Models;

use App\Enums\Level;
use App\Enums\Programme;
use App\Support\Classes\Arms;
use App\Support\Timetables\TimeOfDay;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One paper on the exam timetable. Who sits it is limited like an
 * election's voters: by levels, programmes and courses (arms), where none
 * means everyone.
 *
 * @property int $id
 * @property Carbon $date
 * @property string $starts_at
 * @property string|null $ends_at
 * @property string $course_code
 * @property string|null $course_title
 * @property string|null $venue
 * @property list<string>|null $levels
 * @property list<string>|null $programmes
 * @property list<string>|null $arms
 * @property string|null $note
 * @property Carbon|null $archived_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Exam extends Model
{
    protected $fillable = ['date', 'starts_at', 'ends_at', 'course_code', 'course_title', 'venue', 'levels', 'programmes', 'arms', 'note'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'ends_at' => null,
        'course_title' => null,
        'venue' => null,
        'levels' => null,
        'programmes' => null,
        'arms' => null,
        'note' => null,
        'archived_at' => null,
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'levels' => 'array',
            'programmes' => 'array',
            'arms' => 'array',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * The current exam timetable, in order.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function current(Builder $query): void
    {
        $query->whereNull('archived_at')->orderBy('date')->orderBy('starts_at')->orderBy('course_code');
    }

    /**
     * Whether the student sits this paper, going by their class.
     */
    public function isFor(User $user): bool
    {
        $levels = $this->levels ?? [];
        $programmes = $this->programmes ?? [];
        $arms = $this->arms ?? [];

        return ($levels === [] || in_array($user->level->value, $levels, true))
            && ($programmes === [] || in_array($user->programme->value, $programmes, true))
            && ($arms === [] || in_array($user->arm, $arms, true));
    }

    /**
     * Who sits it, in a few words: "HND1 SWD", "Part-time ND2", "Everyone".
     */
    public function audience(): string
    {
        $words = [
            ...array_map(fn (string $p) => Programme::tryFrom($p)?->label() ?? $p, $this->programmes ?? []),
            ...array_map(fn (string $l) => Level::tryFrom($l)?->label() ?? $l, $this->levels ?? []),
            ...array_map(fn (string $a) => Arms::short($a), $this->arms ?? []),
        ];

        return $words === [] ? 'Everyone' : implode(' ', $words);
    }

    /** "9:00 am – 12:00 pm", or the start alone. */
    public function timeRange(): string
    {
        return TimeOfDay::format($this->starts_at).($this->ends_at !== null ? ' – '.TimeOfDay::format($this->ends_at) : '');
    }

    public function startInput(): string
    {
        return substr($this->starts_at, 0, 5);
    }

    public function endInput(): string
    {
        return $this->ends_at !== null ? substr($this->ends_at, 0, 5) : '';
    }
}
