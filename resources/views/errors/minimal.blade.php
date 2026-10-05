{{--
    Layout for every error page (404, 419, 500, 503…). It must not touch the
    database or session: it may be rendering because they failed.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head', ['title' => trim($__env->yieldContent('title')), 'description' => null])
    <meta name="robots" content="noindex">
</head>
<body class="flex min-h-dvh flex-col items-center justify-center px-4 text-center">
    <main class="animate-enter max-w-md">
        <img src="{{ asset('images/logo-96.webp') }}" alt="" width="64" height="64" class="mx-auto size-16">
        <p class="mt-6 font-display text-6xl font-extrabold tracking-tight text-link">@yield('code')</p>
        <h1 class="mt-3 text-2xl">@yield('message')</h1>
        <p class="mt-2 text-muted">
            @switch(trim($__env->yieldContent('code')))
                @case('404') The page may have moved, or the link may be wrong. @break
                @case('403') You don't have access to this page. @break
                @case('419') Your session expired. Go back, refresh the page and try again. @break
                @case('429') Please wait a moment before trying again. @break
                @case('503') We're doing some maintenance and will be back shortly. @break
                @default Something went wrong on our side. Please try again in a moment.
            @endswitch
        </p>
        <div class="mt-8">
            <x-button href="{{ url('/') }}" icon="house">Go to the home page</x-button>
        </div>
    </main>
</body>
</html>
