<?php

namespace App\Support;

/**
 * The admin area's sections, in the order of its sidebar. Some are pages
 * of their own elsewhere in the app (reviews, announcements, elections).
 */
class AdminNavigation
{
    /**
     * @return list<array{route: string, label: string, icon: string, active: bool}>
     */
    public static function items(): array
    {
        $items = [
            ['admin.dashboard', 'Dashboard', 'layout-dashboard', 'admin.dashboard'],
            ['admin.users.index', 'Users', 'users', 'admin.users.*'],
            ['admin.accounts.index', 'Create accounts', 'user-plus', 'admin.accounts.*'],
            ['roll.index', 'Nominal roll', 'clipboard-list', 'roll.*'],
            ['admin.books.index', 'Books', 'library-big', 'admin.books.*'],
            ['review.index', 'Review uploads', 'shield-check', 'review.*'],
            ['announcements.manage', 'Announcements', 'bell', 'announcements.*'],
            ['elections.manage', 'Elections', 'vote', 'elections.manage*'],
            ['admin.reports', 'Reports', 'chart-column', 'admin.reports'],
            ['admin.audit', 'Audit log', 'scroll-text', 'admin.audit'],
            ['admin.settings', 'Settings', 'settings', 'admin.settings*'],
        ];

        return array_map(fn (array $item) => [
            'route' => $item[0],
            'label' => $item[1],
            'icon' => $item[2],
            'active' => request()->routeIs($item[3]),
        ], $items);
    }
}
