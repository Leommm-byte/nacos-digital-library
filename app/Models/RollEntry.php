<?php

namespace App\Models;

use App\Enums\Level;
use App\Enums\Programme;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One student on the nominal roll (see the nominal_roll migrations). The
 * roll is kept per class: programme and level.
 *
 * @property int $id
 * @property string $matric_number
 * @property string|null $fullname
 * @property string|null $email
 * @property Level|null $level
 * @property Programme|null $programme
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class RollEntry extends Model
{
    protected $table = 'nominal_roll';

    protected $fillable = ['matric_number', 'fullname', 'email', 'level', 'programme'];

    protected function casts(): array
    {
        return [
            'level' => Level::class,
            'programme' => Programme::class,
        ];
    }
}
