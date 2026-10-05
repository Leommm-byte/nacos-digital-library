{{-- A full-screen layout for reading: no site header, footer or tab bar. --}}
@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head', ['title' => $title, 'description' => null])
    <meta name="robots" content="noindex">
</head>
<body class="reader-body">
    {{ $slot }}
</body>
</html>
