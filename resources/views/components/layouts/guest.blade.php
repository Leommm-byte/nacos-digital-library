{{--
    Sign-in, sign-up and recovery pages. Desktop: a green brand panel beside
    the form. Phones: a green band at the top with the form card over it.
--}}
@props(['title' => null, 'description' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head', ['title' => $title, 'description' => $description])
</head>
<body class="auth-shell">
    <a href="#main" class="skip-link">Skip to content</a>

    <aside class="auth-brand">
        <div class="hero-pattern" aria-hidden="true"></div>
        <a href="{{ url('/') }}" class="auth-logo">
            <img src="{{ asset('images/logo-96.webp') }}" srcset="{{ asset('images/logo-96.webp') }} 96w, {{ asset('images/logo-192.webp') }} 192w" sizes="48px" alt="" width="48" height="48" decoding="async">
            <span>NACOS <span class="text-yellow-300">YabaTech</span></span>
        </a>

        <div class="auth-brand-body">
            <h2 class="auth-brand-title">Books, past questions and NACOS life, in one place.</h2>
            <ul class="auth-brand-points">
                <li><x-icon name="library-big" /> Course books for your department and level</li>
                <li><x-icon name="bookmark" /> Save what you need for exams</li>
                <li><x-icon name="vote" /> Vote in NACOS elections with a secret ballot</li>
            </ul>
        </div>

        <p class="auth-brand-foot">Nigeria Association of Computing Students · Yaba College of Technology</p>
    </aside>

    <div class="auth-main">
        <header class="auth-topbar">
            <a href="{{ url('/') }}" class="auth-logo auth-logo-compact">
                <img src="{{ asset('images/logo-96.webp') }}" alt="" width="36" height="36" decoding="async">
                <span>NACOS <span class="text-yellow-300">YabaTech</span></span>
            </a>
            <x-theme-toggle />
        </header>

        <main id="main" tabindex="-1" class="auth-card animate-enter outline-none">
            @include('partials.flash')

            {{ $slot }}
        </main>
    </div>
</body>
</html>
