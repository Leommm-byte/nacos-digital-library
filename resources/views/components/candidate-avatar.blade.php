{{-- A candidate's photo, or their initials on a tinted circle without one. --}}
@props(['name', 'photo' => null, 'size' => 'md'])

@php
    $initials = collect(preg_split('/\s+/', trim($name)) ?: [])
        ->filter()
        ->take(2)
        ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
        ->implode('');
    $tones = ['green', 'blue', 'violet', 'yellow'];
    $tone = $tones[crc32($name) % count($tones)];
    $pixels = ['sm' => 36, 'md' => 44, 'lg' => 56, 'xl' => 96][$size] ?? 44;
@endphp

@if ($photo)
    <span {{ $attributes->class(['candidate-avatar', 'candidate-avatar-'.$size, 'has-photo']) }} aria-hidden="true">
        <img src="{{ $photo }}" alt="" width="{{ $pixels }}" height="{{ $pixels }}" loading="lazy" decoding="async">
    </span>
@else
    <span {{ $attributes->class(['candidate-avatar', 'candidate-avatar-'.$size, 'icon-tile-'.$tone]) }} aria-hidden="true">{{ $initials }}</span>
@endif
