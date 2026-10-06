<?php

namespace App\Support\Uploads;

use App\Enums\BookStatus;
use App\Enums\Role;
use App\Models\Book;
use App\Models\User;

/**
 * Per-user upload caps (config/uploads.php): a number per rolling 24 hours
 * and a number waiting for review at once. Governors and admins, who
 * review and manage the library, are exempt.
 */
class UploadLimits
{
    public static function exempt(User $user): bool
    {
        return $user->hasRole(Role::Governor);
    }

    /**
     * Uploads left in the current 24 hours, or null when unlimited.
     */
    public static function remainingToday(User $user): ?int
    {
        if (self::exempt($user)) {
            return null;
        }

        // Deleted uploads still count, so deleting doesn't reset the cap.
        $used = Book::withTrashed()
            ->where('uploader_id', $user->id)
            ->where('created_at', '>=', now()->subDay())
            ->count();

        return max(0, (int) config('uploads.per_day') - $used);
    }

    /**
     * Why the user can't upload right now, or null if they can.
     */
    public static function blockReason(User $user): ?string
    {
        if (self::exempt($user)) {
            return null;
        }

        if (self::remainingToday($user) === 0) {
            return 'You have reached today\'s limit of '.config('uploads.per_day').' uploads. Please try again tomorrow.';
        }

        $pending = Book::where('uploader_id', $user->id)->where('status', BookStatus::Pending)->count();

        if ($pending >= (int) config('uploads.pending')) {
            return 'You have '.$pending.' uploads waiting for review. You can upload more once some have been reviewed.';
        }

        return null;
    }
}
