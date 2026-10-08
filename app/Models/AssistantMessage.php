<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One message in a student's chat with the assistant: their question
 * ("user") or the answer ("assistant"). Answers keep their links to books
 * and pages separately from the text, so text is never rendered as HTML.
 *
 * @property int $id
 * @property int $user_id
 * @property string $role
 * @property string $body
 * @property list<array{label: string, url: string, note?: string|null}>|null $links
 * @property bool $ai
 * @property Carbon|null $created_at
 */
class AssistantMessage extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'role', 'body', 'links', 'ai'];

    protected function casts(): array
    {
        return [
            'links' => 'array',
            'ai' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
