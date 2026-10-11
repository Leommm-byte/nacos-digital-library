<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Announcement;
use App\Models\User;

/**
 * Everyone reads live announcements for everyone and for their own class.
 * A class's governor manages that class's announcements (whoever posted
 * them, so a new governor takes over); admins read and manage all of them.
 */
class AnnouncementPolicy
{
    public function view(User $user, Announcement $announcement): bool
    {
        if ($this->update($user, $announcement)) {
            return true;
        }

        return $announcement->isLive()
            && ($announcement->isForEveryone() || $announcement->schoolClass()?->key() === $user->schoolClass()?->key());
    }

    public function update(User $user, Announcement $announcement): bool
    {
        if ($user->hasRole(Role::Admin)) {
            return true;
        }

        $class = $user->schoolClass();

        return $user->hasRole(Role::Governor)
            && $class !== null
            && $announcement->schoolClass()?->key() === $class->key();
    }

    public function delete(User $user, Announcement $announcement): bool
    {
        return $this->update($user, $announcement);
    }
}
