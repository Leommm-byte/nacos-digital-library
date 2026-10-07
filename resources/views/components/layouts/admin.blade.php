{{--
    The admin area: the app layout with the admin sections beside the page
    (a sidebar on desktop, a row of tabs that scrolls sideways on phones).
--}}
@props(['title' => null])

<x-layouts.app :title="$title ? $title.' · Admin' : 'Admin'">
    <div class="admin">
        <nav class="admin-nav" aria-label="Admin">
            <p class="admin-nav-title">Admin</p>
            <ul>
                @foreach (\App\Support\AdminNavigation::items() as $item)
                    <li>
                        <a href="{{ route($item['route']) }}" @class(['admin-nav-link', 'is-active' => $item['active']]) @if ($item['active']) aria-current="page" @endif>
                            <x-icon :name="$item['icon']" /> {{ $item['label'] }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>
        <div class="min-w-0">
            {{ $slot }}
        </div>
    </div>
</x-layouts.app>
