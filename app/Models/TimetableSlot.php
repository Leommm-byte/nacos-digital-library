<?php

namespace App\Models;

use App\Enums\Level;
use App\Enums\Programme;
use App\Support\Classes\SchoolClass;
use App\Support\Timetables\TimeOfDay;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One lecture in a class's weekly timetable.
 *
 * @property int $id
 * @property Programme $programme
 * @property Level $level
 * @property string|null $arm
 * @property int $day
 * @property string $starts_at
 * @property string $ends_at
 * @property string $course_code
 * @property string|null $course_title
 * @property string|null $lecturer
 * @property string|null $venue
 * @property int|null $updated_by
 * @property Carbon|null $archived_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class TimetableSlot extends Model
{
    /** Day number => name. Lectures run Monday to Saturday. */
    public const DAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'];

    /** A class's timetable can't grow without limit. */
    public const MAX_PER_CLASS = 80;

    protected $fillable = ['day', 'starts_at', 'ends_at', 'course_code', 'course_title', 'lecturer', 'venue'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'arm' => null,
        'course_title' => null,
        'lecturer' => null,
        'venue' => null,
        'updated_by' => null,
        'archived_at' => null,
    ];

    protected function casts(): array
    {
        return [
            'programme' => Programme::class,
            'level' => Level::class,
            'day' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * The current timetable of a class, in order.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function forClass(Builder $query, SchoolClass $class): void
    {
        $query->whereNull('archived_at')
            ->where('programme', $class->programme)
            ->where('level', $class->level)
            ->when($class->arm === null, fn (Builder $query) => $query->whereNull('arm'), fn (Builder $query) => $query->where('arm', $class->arm))
            ->orderBy('day')
            ->orderBy('starts_at')
            ->orderBy('course_code');
    }

    public function schoolClass(): ?SchoolClass
    {
        return SchoolClass::make($this->programme, $this->level, $this->arm);
    }

    public function assignClass(SchoolClass $class): void
    {
        $this->programme = $class->programme;
        $this->level = $class->level;
        $this->arm = $class->arm;
    }

    public function dayName(): string
    {
        return self::DAYS[$this->day] ?? '';
    }

    /** "8:00 am – 10:00 am" */
    public function timeRange(): string
    {
        return TimeOfDay::format($this->starts_at).' – '.TimeOfDay::format($this->ends_at);
    }

    /** "08:00", for a time input. */
    public function startInput(): string
    {
        return substr($this->starts_at, 0, 5);
    }

    public function endInput(): string
    {
        return substr($this->ends_at, 0, 5);
    }

    /** Colours a course can get (the icon tile tints). */
    public const TONES = ['green', 'blue', 'violet', 'yellow', 'red'];

    /**
     * A colour that's the same for a course all week, so it's easy to
     * spot.
     */
    public function tone(): string
    {
        return self::TONES[crc32(strtoupper($this->course_code)) % count(self::TONES)];
    }

    /**
     * Whether a day name or short form ("Mon", "tues", "THURSDAY") is a
     * lecture day; its number or null.
     */
    public static function dayFrom(?string $value): ?int
    {
        $value = strtolower(trim((string) $value));

        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return isset(self::DAYS[(int) $value]) ? (int) $value : null;
        }

        foreach (self::DAYS as $number => $name) {
            if (strlen($value) >= 2 && str_starts_with(strtolower($name), $value)) {
                return $number;
            }
        }

        return match ($value) {
            'tues' => 2,
            'thur', 'thurs' => 4,
            default => null,
        };
    }
}
