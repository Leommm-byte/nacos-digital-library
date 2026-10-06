{{-- A book's review status, as a badge (uploads pages). --}}
@props(['status'])

@php
    $variant = match ($status) {
        \App\Enums\BookStatus::Approved => 'primary',
        \App\Enums\BookStatus::Pending, \App\Enums\BookStatus::ChangesRequested => 'accent',
        \App\Enums\BookStatus::Rejected => 'danger',
        default => 'neutral',
    };
@endphp

<x-badge :variant="$variant" {{ $attributes }}>{{ $status->label() }}</x-badge>
