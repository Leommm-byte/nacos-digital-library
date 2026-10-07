{{-- Initials of a candidate on a tinted circle. --}}
@props(['name', 'size' => 'md'])

@php
    $initials = collect(preg_split('/\s+/', trim($name)) ?: [])
        ->filter()
        ->take(2)
        ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
        ->implode('');
    $tones = ['green', 'blue', 'violet', 'yellow'];
    $tone = $tones[crc32($name) % count($tones)];
@endphp

<span {{ $attributes->class(['candidate-avatar', 'candidate-avatar-'.$size, 'icon-tile-'.$tone]) }} aria-hidden="true">{{ $initials }}</span>
