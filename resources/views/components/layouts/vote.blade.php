{{--
    A department's election space (/vote/{slug}): its name and logo at the
    top, "Powered by NACOS YabaTech" at the bottom, and nothing else of the
    NACOS app. Expects $organization (shared by the "vote" middleware).
--}}
@props(['title' => null, 'description' => null])

@php
    $voter = auth('voter')->user();
    // A NACOS admin (signed in to the NACOS app) can run any space's Manage pages.
    $nacosAdmin = ! $voter && \App\Http\Middleware\Vote\EnsureDepartmentAdmin::nacosAdmin();
    $site = $organization->name.' elections';
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head', ['title' => $title, 'description' => $description ?? "Elections for {$organization->name}: register, vote with a secret ballot and follow the results.", 'siteName' => $site])
    <meta name="robots" content="noindex">
    @vite('resources/css/vote.css')
</head>
<body @class(['flex min-h-dvh flex-col', \App\Support\Departments\Colours::bodyClass($organization->colour)])>
    <a href="#main" class="skip-link">Skip to content</a>

    <header class="app-header">
        <div class="container-page flex h-full items-center gap-3">
            <a href="{{ route('vote.home') }}" class="vote-brand" aria-label="{{ $organization->name }} elections home">
                @include('vote.partials.logo', ['size' => 'sm'])
                <span class="vote-brand-name">
                    <span class="truncate">{{ $organization->name }}</span>
                    <span class="vote-brand-sub">Elections</span>
                </span>
            </a>

            <div class="ml-auto flex shrink-0 items-center gap-1.5">
                <x-theme-toggle />
                @if ($voter)
                    @if ($voter->isAdmin())
                        <x-button href="{{ route('vote.admin.elections') }}" variant="ghost" size="sm" icon="settings" class="hidden sm:inline-flex">Manage</x-button>
                    @endif
                    <form method="POST" action="{{ route('vote.logout') }}">
                        @csrf
                        <x-button variant="secondary" size="sm" icon="log-out">Log out</x-button>
                    </form>
                @elseif ($nacosAdmin)
                    <x-button href="{{ route('vote.admin.elections') }}" variant="ghost" size="sm" icon="settings" class="hidden sm:inline-flex">Manage</x-button>
                    <x-button href="{{ route('admin.departments.show', $organization) }}" variant="secondary" size="sm" icon="arrow-left">NACOS admin</x-button>
                @elseif (! request()->routeIs('vote.login'))
                    <x-button href="{{ route('vote.login') }}" variant="secondary" size="sm" icon="log-in">Log in</x-button>
                @endif
            </div>
        </div>
    </header>

    <main id="main" tabindex="-1" class="container-page flex-1 py-6 outline-none md:py-10">
        @include('partials.flash', ['toast' => true])

        {{ $slot }}
    </main>

    <footer class="site-footer vote-footer">
        <div class="container-page flex flex-col gap-x-6 gap-y-2 sm:flex-row sm:flex-wrap sm:items-center">
            <p class="flex items-center gap-2">
                <span>Powered by</span>
                <img src="{{ asset('images/logo-96.webp') }}" alt="" width="20" height="20" class="size-5" loading="lazy" decoding="async">
                <span class="font-medium text-fg">NACOS YabaTech</span>
            </p>
            <p class="text-xs">Secret ballot: a record that you voted is kept, never who you chose.</p>
        </div>
    </footer>

    <div id="toast" @class(['toast', 'is-visible' => session('status')]) role="status" aria-live="polite">{{ session('status') }}</div>
</body>
</html>
