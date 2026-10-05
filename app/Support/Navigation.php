<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * The app's primary navigation. Items whose route does not exist yet are
 * skipped, so screens appear in the menu as their PRs land.
 */
class Navigation
{
    /**
     * @return list<array{route: string, label: string, icon: string, active: bool}>
     */
    public static function primary(?User $user): array
    {
        $items = [
            ['route' => 'home', 'label' => 'Home', 'icon' => 'house', 'match' => 'home'],
            ['route' => 'library.index', 'label' => 'Library', 'icon' => 'library-big', 'match' => 'library.*'],
            ['route' => 'bookmarks.index', 'label' => 'Saved', 'icon' => 'bookmark', 'match' => 'bookmarks.*', 'auth' => true],
            ['route' => 'elections.index', 'label' => 'Elections', 'icon' => 'vote', 'match' => 'elections.*'],
            ['route' => 'profile.edit', 'label' => 'Profile', 'icon' => 'user', 'match' => 'profile.*', 'auth' => true],
        ];

        $visible = [];

        foreach ($items as $item) {
            if (! Route::has($item['route']) || (($item['auth'] ?? false) && $user === null)) {
                continue;
            }

            $visible[] = [
                'route' => $item['route'],
                'label' => $item['label'],
                'icon' => $item['icon'],
                'active' => request()->routeIs($item['match']),
            ];
        }

        return $visible;
    }
}
