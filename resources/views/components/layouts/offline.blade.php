{{--
    Pages the service worker keeps on the phone (/offline). They are the
    same for everyone and must never show anything about the person
    signed in: no name, no account menu, no CSRF token.
--}}
@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head', ['title' => $title, 'description' => null])
    <meta name="robots" content="noindex">
</head>
<body class="flex min-h-dvh flex-col">
    <a href="#main" class="skip-link">Skip to content</a>

    <header class="app-header">
        <div class="container-page flex h-full items-center gap-4">
            <a href="{{ url('/') }}" class="flex shrink-0 items-center gap-2.5" aria-label="{{ config('app.name') }} home">
                <img src="{{ asset('images/logo-96.webp') }}" alt="" width="36" height="36" class="size-9">
                <span class="font-display text-lg font-extrabold tracking-tight">NACOS <span class="font-semibold text-link">YabaTech</span></span>
            </a>
            <div class="ml-auto"><x-theme-toggle /></div>
        </div>
    </header>

    <main id="main" tabindex="-1" class="container-page flex-1 py-6 outline-none md:py-10">
        {{ $slot }}
    </main>
</body>
</html>
