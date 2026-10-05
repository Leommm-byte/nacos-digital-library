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
