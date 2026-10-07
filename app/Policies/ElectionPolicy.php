<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Election;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Drafts are only visible to admins. Once an election is launched, nothing
 * about it can change except closing it.
 */
class ElectionPolicy
{
    public function view(?User $user, Election $election): bool
    {
        return ! $election->isDraft() || ($user !== null && $user->hasRole(Role::Admin));
    }

    public function update(User $user, Election $election): Response
    {
        if (! $user->hasRole(Role::Admin)) {
            return Response::deny();
        }

        return $election->isDraft()
            ? Response::allow()
            : Response::deny('This election has started, so its details, positions and candidates can no longer change.');
    }

    public function delete(User $user, Election $election): Response
    {
        return $this->update($user, $election);
    }
}
