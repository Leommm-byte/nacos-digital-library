<?php

namespace App\Support;

use App\Models\User;

/**
 * Offline support (resources/js/offline.js, resources/sw/sw.js).
 */
class Offline
{
    /**
     * An opaque id for whose books are kept on a phone. When a different
     * account signs in on that phone, the books are deleted. Derived from
     * the app key so it reveals nothing about the student.
     */
    public static function owner(User $user): string
    {
        return substr(hash_hmac('sha256', 'offline-owner:'.$user->id, (string) config('app.key')), 0, 20);
    }
}
