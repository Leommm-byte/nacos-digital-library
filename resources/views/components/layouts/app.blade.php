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
<body @class(['flex min-h-dvh flex-col', 'has-tabbar' => $tabbar]) @if ($user) data-user="{{ \App\Support\Offline::owner($user) }}" @endif>
    <a href="#main" class="skip-link">Skip to content</a>

    @include('partials.header', ['user' => $user, 'nav' => $nav])

    <main id="main" tabindex="-1" class="container-page flex-1 py-6 outline-none md:py-10">
        @include('partials.flash', ['toast' => true])
        @include('partials.account-notice')

        {{ $slot }}
    </main>

    @include('partials.footer')

    @if ($tabbar)
        @include('partials.tabbar', ['nav' => $nav])
    @endif

    @if ($user && Route::has('assistant.index') && ! request()->routeIs('assistant.*'))
        @include('assistant.panel')
    @endif

    {{-- Success messages appear here and fade away (resources/js/toast.js). --}}
    <div id="toast" @class(['toast', 'is-visible' => session('status')]) role="status" aria-live="polite">{{ session('status') }}</div>
</body>
</html>
