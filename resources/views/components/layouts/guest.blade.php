{{-- Centred single-card layout for sign-in, sign-up and similar pages. --}}
@props(['title' => null, 'description' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head', ['title' => $title, 'description' => $description])
</head>
<body class="flex min-h-dvh flex-col">
    <a href="#main" class="skip-link">Skip to content</a>

    <div class="flex justify-end p-3">
        <x-theme-toggle />
    </div>

    <main id="main" tabindex="-1" class="flex flex-1 flex-col items-center justify-center px-4 pb-12 outline-none">
        <a href="{{ url('/') }}" class="animate-enter mb-6 flex flex-col items-center gap-3">
            <img src="{{ asset('images/logo-96.webp') }}" alt="" width="64" height="64" class="size-16">
            <span class="font-display text-xl font-extrabold tracking-tight">NACOS <span class="text-link">YabaTech</span></span>
        </a>

        <div class="card animate-enter w-full max-w-md p-6 sm:p-8">
            @include('partials.flash')

            {{ $slot }}
        </div>
    </main>
</body>
</html>
