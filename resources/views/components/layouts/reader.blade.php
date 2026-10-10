{{-- A full-screen layout for reading: no site header, footer or tab bar. --}}
@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head', ['title' => $title, 'description' => null])
    <meta name="robots" content="noindex">
</head>
@php
    // The offline reader (offline/reader) is the same for everyone.
    $user = request()->routeIs('offline.*') ? null : auth()->user();
@endphp
<body class="reader-body" @if ($user) data-user="{{ \App\Support\Offline::owner($user) }}" @endif>
    {{ $slot }}
</body>
</html>
