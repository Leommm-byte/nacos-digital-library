<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

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
}
