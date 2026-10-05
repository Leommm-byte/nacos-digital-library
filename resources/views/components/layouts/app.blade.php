@props(['title' => null, 'description' => null])

@php
    $user = auth()->user();
    $nav = \App\Support\Navigation::primary($user);
    // A tab bar with a single tab is pointless; it appears once there are more.
    $tabbar = count($nav) > 1;
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head', ['title' => $title, 'description' => $description])
</head>
<body @class(['flex min-h-dvh flex-col', 'has-tabbar' => $tabbar])>
    <a href="#main" class="skip-link">Skip to content</a>

    @include('partials.header', ['user' => $user, 'nav' => $nav])

    <main id="main" tabindex="-1" class="container-page flex-1 py-6 outline-none md:py-10">
        @include('partials.flash')
        @include('partials.account-notice')

        {{ $slot }}
    </main>

    @include('partials.footer')

    @if ($tabbar)
        @include('partials.tabbar', ['nav' => $nav])
    @endif

    <div id="toast" class="toast" aria-hidden="true"></div>
    <div id="live-region" class="sr-only" aria-live="polite"></div>
</body>
</html>
