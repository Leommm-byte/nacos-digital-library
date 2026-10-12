<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An emailed invitation to choose a password for an account made from the
 * nominal roll (see App\Support\Accounts\Invitations). Waiting until the
 * scheduler sends it, then sent until it's used or expires.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $invited_by
 * @property string|null $token_hash
 * @property bool $priority
 * @property int $sends
 * @property Carbon|null $sent_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon $created_at
 * @property-read User $user
 */
class AccountInvitation extends Model
{
    public const WAITING = 'waiting';

    public const SENT = 'sent';

    public const EXPIRED = 'expired';

    public const ACCEPTED = 'accepted';

    protected $fillable = ['user_id', 'invited_by', 'priority'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'invited_by' => null,
        'token_hash' => null,
        'priority' => false,
        'sends' => 0,
        'sent_at' => null,
        'expires_at' => null,
        'accepted_at' => null,
    ];

    protected function casts(): array
    {
        return [
            'priority' => 'boolean',
            'sends' => 'integer',
            'sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /**
     * Not used yet: waiting to be sent, sent, or expired.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function unused(Builder $query): void
    {
        $query->whereNull('accepted_at');
    }

    /**
     * Waiting for the scheduler to send them.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function waiting(Builder $query): void
    {
        $query->whereNull('accepted_at')->whereNull('sent_at');
    }

    public function status(): string
    {
        return match (true) {
            $this->accepted_at !== null => self::ACCEPTED,
            $this->sent_at === null => self::WAITING,
            $this->expires_at !== null && $this->expires_at->isPast() => self::EXPIRED,
            default => self::SENT,
        };
    }

    /**
     * Started by the student signing up themselves (their class list had
     * their email, so the link went there).
     */
    public function isFromSignup(): bool
    {
        return $this->invited_by === $this->user_id;
    }

    /**
     * Queues it to be sent again, ahead of the rest, with a new link. The
     * old link stops working straight away.
     */
    public function resend(): void
    {
        $this->forceFill(['token_hash' => null, 'sent_at' => null, 'expires_at' => null, 'priority' => true])->save();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
