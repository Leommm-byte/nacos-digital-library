{{-- Shared <head> contents. Expects $title and $description (both optional). --}}
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>{{ $title ? $title.' · '.config('app.name') : config('app.name') }}</title>
<meta name="description" content="{{ $description ?? 'Books, past questions, elections and student services for NACOS YabaTech.' }}">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#111b17" media="(prefers-color-scheme: dark)">
<meta name="format-detection" content="telephone=no">
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="48x48">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
<link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="NACOS">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
{{-- Link previews in WhatsApp, X, Facebook and others. --}}
<meta property="og:type" content="website">
<meta property="og:site_name" content="{{ config('app.name') }}">
<meta property="og:title" content="{{ $title ?: config('app.name') }}">
<meta property="og:description" content="{{ $description ?? 'Books, past questions, elections and student services for NACOS YabaTech.' }}">
<meta property="og:url" content="{{ url()->current() }}">
<meta property="og:image" content="{{ asset('images/share.png') }}">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="NACOS YabaTech: books, past questions, elections and student services.">
<meta property="og:locale" content="en_NG">
<meta name="twitter:card" content="summary_large_image">
{{-- Runs before first paint: marks JS as available and applies a saved
     theme choice, so the page never flashes the wrong theme. --}}
<script nonce="{{ Vite::cspNonce() }}">
    document.documentElement.classList.add('js');
    try {
        var t = localStorage.getItem('theme');
        if (t === 'light' || t === 'dark') document.documentElement.dataset.theme = t;
    } catch (e) {}
</script>
@vite(['resources/css/app.css', 'resources/js/app.js'])
