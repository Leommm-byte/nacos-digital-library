@php
    // Small counts for the header: unread notifications for everyone,
    // uploads waiting for review for reviewers.
    $unread = $user && Route::has('notifications.index') ? $user->unreadNotifications()->count() : 0;
    $waiting = $user && Route::has('review.index') && Gate::allows('review-uploads')
        ? \App\Models\Book::reviewableBy($user)->where('status', \App\Enums\BookStatus::Pending)->count()
        : null;
@endphp

<header class="app-header">
    <div class="container-page flex h-full items-center gap-4">
        <a href="{{ url('/') }}" class="flex shrink-0 items-center gap-2.5" aria-label="{{ config('app.name') }} home">
            <img src="{{ asset('images/logo-96.webp') }}" alt="" width="36" height="36" class="size-9">
            <span class="font-display text-lg font-extrabold tracking-tight">NACOS <span class="font-semibold text-link">YabaTech</span></span>
        </a>

        @if ($nav)
            <nav aria-label="Main" class="ml-4 hidden h-full items-center gap-1 md:flex">
                @foreach ($nav as $item)
                    <a href="{{ route($item['route']) }}" class="nav-link" @if ($item['active']) aria-current="page" @endif>
                        {{ $item['label'] }}
                    </a>
                @endforeach
            </nav>
        @endif

        <div class="ml-auto flex items-center gap-1.5">
            @if ($user && Route::has('library.index') && ! request()->routeIs('library.index'))
                <form method="GET" action="{{ route('library.index') }}" role="search" class="header-search">
                    <label for="header-search" class="sr-only">Search the library</label>
                    <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-muted" />
                    <input id="header-search" name="q" type="search" placeholder="Search the library" enterkeyhint="search">
                </form>
                <a href="{{ route('library.index') }}" class="btn btn-ghost btn-icon lg:hidden" aria-label="Search the library">
                    <x-icon name="search" />
                </a>
            @endif
            {{-- On phones the theme button lives in the account menu, to make room. --}}
            <div @class(['hidden sm:block' => (bool) $user])><x-theme-toggle /></div>

            @if ($user && Route::has('notifications.index'))
                <a href="{{ route('notifications.index') }}" class="btn btn-ghost btn-icon relative"
                    aria-label="Notifications{{ $unread ? ' ('.$unread.' unread)' : '' }}" @if (request()->routeIs('notifications.*')) aria-current="page" @endif>
                    <x-icon name="bell" />
                    @if ($unread)
                        <span class="count-badge" aria-hidden="true">{{ $unread > 9 ? '9+' : $unread }}</span>
                    @endif
                </a>
            @endif

            @if ($user)
                <details class="menu">
                    <summary class="flex items-center gap-1 rounded-full p-0.5" aria-label="Account menu">
                        <span class="avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($user->greetingName(), 0, 1)) }}</span>
                        <x-icon name="chevron-down" class="hidden text-muted sm:block" />
                    </summary>
                    <div class="menu-panel">
                        <div class="border-b border-border px-2.5 pt-1.5 pb-2.5 mb-1">
                            <p class="truncate font-semibold">{{ $user->fullname }}</p>
                            <p class="truncate text-sm text-muted">{{ $user->matric_number }} · {{ $user->role->label() }}</p>
                        </div>
                        @if (Route::has('profile.edit'))
                            <a href="{{ route('profile.edit') }}" class="menu-item"><x-icon name="user" /> Profile</a>
                        @endif
                        @if (Route::has('bookmarks.index'))
                            <a href="{{ route('bookmarks.index') }}" class="menu-item"><x-icon name="bookmark" /> Saved books</a>
                        @endif
                        @if ($waiting !== null)
                            <a href="{{ route('review.index') }}" class="menu-item"><x-icon name="shield-check" /> Review uploads
                                @if ($waiting)<span class="menu-count">{{ $waiting }}</span>@endif
                            </a>
                        @endif
                        @can('manage-announcements')
                            <a href="{{ route('announcements.manage') }}" class="menu-item"><x-icon name="bell" /> Announcements</a>
                        @endcan
                        @can('manage-elections')
                            <a href="{{ route('elections.manage') }}" class="menu-item"><x-icon name="vote" /> Manage elections</a>
                        @endcan
                        @if (Route::has('uploads.index'))
                            <a href="{{ route('uploads.index') }}" class="menu-item"><x-icon name="upload" /> Your uploads</a>
                        @endif
                        @if (Route::has('settings'))
                            <a href="{{ route('settings') }}" class="menu-item"><x-icon name="settings" /> Account settings</a>
                        @endif
                        @can('issue-reset-codes')
                            <a href="{{ route('reset-codes.create') }}" class="menu-item"><x-icon name="key-round" /> Reset codes</a>
                        @endcan
                        @if (Route::has('admin.dashboard') && Gate::allows('access-admin'))
                            <a href="{{ route('admin.dashboard') }}" class="menu-item"><x-icon name="shield-check" /> Admin panel</a>
                        @endif
                        <div class="menu-item justify-between sm:hidden">
                            <span>Theme</span>
                            <x-theme-toggle />
                        </div>
                        @if (Route::has('logout'))
                            <form method="POST" action="{{ route('logout') }}" class="mt-1 border-t border-border pt-1" data-logout>
                                @csrf
                                <button type="submit" class="menu-item text-danger"><x-icon name="log-out" /> Log out</button>
                            </form>
                        @endif
                    </div>
                </details>
            @elseif (Route::has('login'))
                <x-button href="{{ route('login') }}" size="sm" icon="log-in">Log in</x-button>
            @endif
        </div>
    </div>
</header>
