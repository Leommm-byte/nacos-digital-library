<?php

namespace App\Support\Dashboard;

use App\Models\Announcement;
use Illuminate\Support\Facades\Cache;

/**
 * Live announcements, newest first. Read on every home page view, so the
 * list is cached briefly and cleared whenever an announcement changes.
 */
class Announcements
{
    private const CACHE_KEY = 'announcements:live';

    /**
     * @return list<array{id: int, title: string, body: string, date: string}>
     */
    public static function latest(int $limit): array
    {
        /** @var list<array{id: int, title: string, body: string, date: string}> $live */
        $live = Cache::remember(self::CACHE_KEY, 300, fn () => Announcement::query()
            ->live()
            ->orderByRaw('coalesce(starts_at, created_at) desc')
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (Announcement $a) => [
                'id' => $a->id,
                'title' => $a->title,
                'body' => $a->body,
                'date' => ($a->starts_at ?? $a->created_at)->toIso8601String(),
            ])
            ->all());

        return array_slice($live, 0, $limit);
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
