<?php

namespace App\Models;

use App\Enums\Level;
use App\Enums\Programme;
use App\Support\Classes\Arms;
use App\Support\Classes\SchoolClass;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One student on the nominal roll (see the nominal_roll migrations). The
 * roll is kept per class: programme, level and arm (HND SWD or NCC).
 *
 * @property int $id
 * @property string $matric_number
 * @property string|null $fullname
 * @property string|null $email
 * @property Level|null $level
 * @property Programme|null $programme
 * @property string|null $arm
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class RollEntry extends Model
{
    protected $table = 'nominal_roll';

    protected $fillable = ['matric_number', 'fullname', 'email', 'level', 'programme', 'arm'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'arm' => null,
    ];

    protected function casts(): array
    {
        return [
            'level' => Level::class,
            'programme' => Programme::class,
        ];
    }

    public function schoolClass(): ?SchoolClass
    {
        return SchoolClass::make($this->programme, $this->level, $this->arm);
    }

    /** "HND1 SWD Full-time"; empty when the class isn't known. */
    public function classLabel(): string
    {
        return $this->schoolClass()?->label()
            ?? implode(' ', array_filter([$this->level?->label(), Arms::short($this->arm), $this->programme?->label()]));
    }
}
