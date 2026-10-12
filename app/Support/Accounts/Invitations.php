<?php

namespace App\Support\Accounts;

use App\Models\AccountInvitation;
use App\Models\User;
use App\Notifications\AccountInvitation as InvitationEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Invitations to accounts made from the nominal roll. Instead of a password
 * on a slip, the student gets an email with a one-time link to choose
 * their own (nobody else ever sees it). Expires after 7 days; an admin, or
 * the student through "Forgot password", can have it sent again.
 *
 * Emails go out a few at a time (`invitations:send`, every minute), staying
 * under the host's hourly limit. Only a hash of each link's token is kept,
 * and a new one is made each time it's sent.
 */
class Invitations
{
    /**
     * Queues an invitation for an account that has an email address.
     */
    public static function queue(User $user, ?User $by = null, bool $priority = false): AccountInvitation
    {
        $invitation = AccountInvitation::query()->firstOrNew(['user_id' => $user->id]);
        $invitation->forceFill([
            'invited_by' => $by !== null ? $by->id : $invitation->invited_by,
            'token_hash' => null,
            'priority' => $priority,
            'sent_at' => null,
            'expires_at' => null,
            'accepted_at' => null,
        ])->save();

        return $invitation;
    }

    /**
     * How many more may be sent this hour.
     */
    public static function allowance(): int
    {
        $sentThisHour = AccountInvitation::query()->where('sent_at', '>', now()->subHour())->count();

        return max(0, min(
            (int) config('mail.invitations.per_minute'),
            (int) config('mail.invitations.per_hour') - $sentThisHour,
        ));
    }

    /**
     * Sends the waiting invitations the hourly allowance has room for:
     * sign-ups and re-sends first, then the rest in the order they were
     * made. Stops at the first failure (the mail server is likely down)
     * and tries again next minute.
     */
    public static function sendDue(): int
    {
        $limit = self::allowance();

        if ($limit === 0) {
            return 0;
        }

        $due = AccountInvitation::query()->waiting()
            ->with('user')
            ->orderByDesc('priority')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $sent = 0;

        foreach ($due as $invitation) {
            $user = $invitation->user;

            // No address to send to (or the account is suspended): marked
            // expired, so it shows on the admin's list to fix and re-send.
            if ($user->email === null || $user->isSuspended()) {
                $invitation->forceFill(['sent_at' => now(), 'expires_at' => now()])->save();

                continue;
            }

            $token = Str::random(48);

            try {
                DB::transaction(function () use ($invitation, $user, $token) {
                    $invitation->forceFill([
                        'token_hash' => hash('sha256', $token),
                        'sent_at' => now(),
                        'expires_at' => now()->addDays((int) config('mail.invitations.expire_days')),
                        'sends' => $invitation->sends + 1,
                    ])->save();

                    // Sent now, inside the transaction: if the mail server
                    // refuses it, it stays waiting.
                    $user->notifyNow(new InvitationEmail($token, $invitation->isFromSignup()));
                });
            } catch (Throwable $e) {
                report($e);

                break;
            }

            $sent++;
        }

        return $sent;
    }

    /**
     * The unused, unexpired invitation a link belongs to.
     */
    public static function find(string $token): ?AccountInvitation
    {
        if (strlen($token) !== 48) {
            return null;
        }

        $invitation = AccountInvitation::query()->unused()
            ->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now())
            ->with('user')
            ->first();

        return $invitation !== null && ! $invitation->user->isSuspended() ? $invitation : null;
    }

    /**
     * "a•••@gmail.com": enough for a student to recognise their address
     * without showing it to whoever typed their matric number.
     */
    public static function mask(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($name, 0, 1).'•••@'.$domain;
    }
}
