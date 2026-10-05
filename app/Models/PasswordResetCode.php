<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $issued_by
 * @property string $code_hash
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 */
class PasswordResetCode extends Model
{
    public const LIFETIME_MINUTES = 30;

    protected $fillable = ['user_id', 'issued_by', 'code_hash', 'expires_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function usable(Builder $query): void
    {
        $query->whereNull('used_at')->where('expires_at', '>', now());
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
