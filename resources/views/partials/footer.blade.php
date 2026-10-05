<footer class="site-footer">
    <div class="container-page flex flex-col gap-6 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-3">
            <img src="{{ asset('images/logo-96.webp') }}" alt="" width="32" height="32" class="size-8" loading="lazy" decoding="async">
            <div>
                <p class="font-semibold text-fg">NACOS YabaTech</p>
                <p>Nigeria Association of Computing Students, Yaba College of Technology</p>
            </div>
        </div>
        @auth
            <nav aria-label="Footer" class="flex flex-wrap gap-x-5 gap-y-2">
                @foreach (['library.index' => 'Library', 'bookmarks.index' => 'Saved', 'profile.edit' => 'Profile', 'settings' => 'Settings'] as $route => $label)
                    @if (Route::has($route))
                        <a href="{{ route($route) }}" class="hover:text-fg">{{ $label }}</a>
                    @endif
                @endforeach
            </nav>
        @endauth
    </div>
    <div class="container-page">
        <p class="mt-6 border-t border-border pt-5 text-xs">&copy; {{ now()->year }} NACOS YabaTech · Built by students, for students.</p>
    </div>
</footer>
