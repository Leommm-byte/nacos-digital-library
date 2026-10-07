<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class UserPolicy
{
    /**
     * Who may give someone a one-time password reset code: an admin for
     * anyone else; a course rep (or governor) for students in their own
     * department and level, i.e. their class. Nobody issues one for
     * themselves.
     */
    public function issueResetCode(User $actor, User $target): bool
    {
        if ($actor->is($target)) {
            return false;
        }

        if ($actor->hasRole(Role::Admin)) {
            return true;
        }

        return $actor->hasRole(Role::CourseRep)
            && ! $target->hasRole(Role::CourseRep)
            && $actor->department_id === $target->department_id
            && $actor->level === $target->level;
    }

    /**
     * Admins manage other people's accounts (role, suspension, class,
     * two-step, a new password). Never their own here: an admin can't lock
     * themselves out or demote themselves by accident.
     */
    public function manage(User $actor, User $target): Response
    {
        if (! $actor->hasRole(Role::Admin)) {
            return Response::deny();
        }

        return $actor->is($target)
            ? Response::deny('You can\'t change your own account here. Ask another admin.')
            : Response::allow();
    }
}
