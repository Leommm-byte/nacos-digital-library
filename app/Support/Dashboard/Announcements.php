<?php

namespace App\Support\Dashboard;

use App\Models\Announcement;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

/**
 * Live announcements, newest first. Read on every home page view, so the
 * live ones are cached briefly for everyone (cleared whenever one
 * changes) and each person's are picked from them: everyone's, their own
 * class's, or every one for admins.
 */
class Announcements
{
    private const CACHE_KEY = 'announcements:live:v2';

    /** Enough for every class's latest few as well as everyone's. */
    private const CACHED = 60;

    /**
     * @return list<array{id: int, title: string, excerpt: string, long: bool, class: string|null, audience: string, date: string}>
     */
    public static function latest(User $user, int $limit): array
    {
        /** @var list<array{id: int, title: string, excerpt: string, long: bool, class: string|null, audience: string, date: string}> $live */
        $live = Cache::remember(self::CACHE_KEY, 300, fn () => Announcement::query()
            ->live()
            ->orderByRaw('coalesce(starts_at, created_at) desc')
            ->orderByDesc('id')
            ->limit(self::CACHED)
            ->get()
            ->map(fn (Announcement $a) => [
                'id' => $a->id,
                'title' => $a->title,
                'excerpt' => $a->excerpt(160),
                'long' => $a->isLong(160),
                'class' => $a->schoolClass()?->key(),
                'audience' => $a->audience(),
                'date' => $a->date()->toIso8601String(),
            ])
            ->all());

        $all = Gate::forUser($user)->allows('view-all-announcements');
        $mine = $user->schoolClass()?->key();

        $visible = array_filter($live, fn (array $a) => $all || $a['class'] === null || $a['class'] === $mine);

        return array_slice(array_values($visible), 0, $limit);
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
