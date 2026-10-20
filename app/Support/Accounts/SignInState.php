<?php

namespace App\Support\Accounts;

use App\Models\AccountInvitation;
use App\Models\User;

/**
 * Whether someone has started using their account, for the admin's user
 * list. Accounts made from the nominal roll exist before the student has
 * done anything, so "has an account" isn't the same as "has signed up":
 * only signing in (with a slip, an invitation link or their own password)
 * counts. Needs the user's invitation loaded.
 */
final class SignInState
{
    private function __construct(
        public readonly bool $signedIn,
        public readonly string $label,
        public readonly string $detail,
    ) {}

    public static function for(User $user): self
    {
        if ($user->last_login_at !== null) {
            return new self(true, 'Signed in', 'Last on '.$user->last_login_at->timezone(config('app.display_timezone'))->format('j M Y'));
        }

        $invitation = $user->invitation;

        return new self(false, 'Not signed in yet', match ($invitation?->status()) {
            AccountInvitation::WAITING => 'Invitation not sent yet',
            AccountInvitation::SENT => 'Invitation emailed',
            AccountInvitation::EXPIRED => 'Invitation expired',
            default => $user->must_change_password ? 'Has a password slip' : 'Never signed in',
        });
    }
}
