<?php

namespace App\Models;

use App\Enums\Level;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One student on the nominal roll (see the nominal_roll migration).
 *
 * @property int $id
 * @property string $matric_number
 * @property string|null $fullname
 * @property Level|null $level
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class RollEntry extends Model
{
    protected $table = 'nominal_roll';

    protected $fillable = ['matric_number', 'fullname', 'level'];

    protected function casts(): array
    {
        return [
            'level' => Level::class,
        ];
    }
}
