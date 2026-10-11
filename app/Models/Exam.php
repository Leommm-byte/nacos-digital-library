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
 * One paper on a programme's exam timetable (full-time, part-time and
 * CODFEL write exams at different times). Within the programme, who sits
 * it can be limited by levels and courses (arms); none means everyone in
 * the programme.
 *
 * @property int $id
 * @property Programme $programme
 * @property Carbon $date
 * @property string $starts_at
 * @property string|null $ends_at
 * @property string $course_code
 * @property string|null $course_title
 * @property string|null $venue
 * @property list<string>|null $levels
 * @property list<string>|null $arms
 * @property string|null $note
 * @property Carbon|null $archived_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Exam extends Model
{
    protected $fillable = ['programme', 'date', 'starts_at', 'ends_at', 'course_code', 'course_title', 'venue', 'levels', 'arms', 'note'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'ends_at' => null,
        'course_title' => null,
        'venue' => null,
        'levels' => null,
        'arms' => null,
        'note' => null,
        'archived_at' => null,
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'levels' => 'array',
            'programme' => Programme::class,
            'arms' => 'array',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * A programme's current exam timetable, in order (every programme's
     * without one).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function current(Builder $query, ?Programme $programme = null): void
    {
        $query->whereNull('archived_at')
            ->when($programme !== null, fn (Builder $query) => $query->where('programme', $programme))
            ->orderBy('date')->orderBy('starts_at')->orderBy('course_code');
    }

    /**
     * Whether the student sits this paper, going by their class.
     */
    public function isFor(User $user): bool
    {
        if ($user->isGraduated()) {
            return false;
        }

        $levels = $this->levels ?? [];
        $arms = $this->arms ?? [];

        return $user->programme === $this->programme
            && ($levels === [] || in_array($user->level->value, $levels, true))
            && ($arms === [] || in_array($user->arm, $arms, true));
    }

    /**
     * Who sits it within the programme, in a few words: "HND1 SWD",
     * "ND1 ND2", "Everyone".
     */
    public function audience(): string
    {
        $words = [
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
