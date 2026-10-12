<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One of the current executives, shown on "Know your executives".
 *
 * @property int $id
 * @property string $name
 * @property string $position
 * @property string|null $details
 * @property string|null $photo_path
 * @property int $sort_order
 * @property Carbon $created_at
 */
class Executive extends Model
{
    protected $fillable = ['name', 'position', 'details', 'sort_order'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'details' => null,
        'photo_path' => null,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    /**
     * Where the photo is served, or null without one. The version changes
     * with each new photo, so browsers may keep it.
     */
    public function photoUrl(): ?string
    {
        if ($this->photo_path === null) {
            return null;
        }

        return route('executives.photo', ['executive' => $this->id, 'v' => substr(sha1($this->photo_path), 0, 10)]);
    }
}
