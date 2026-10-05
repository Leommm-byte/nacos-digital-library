{{--
    <x-button>Save</x-button>
    <x-button href="/library" variant="secondary" icon="book-open">Browse</x-button>
    Variants: primary, accent, secondary, ghost, danger. Sizes: md, sm.
--}}
@props(['variant' => 'primary', 'size' => 'md', 'href' => null, 'icon' => null, 'type' => 'submit'])

@php
    $classes = ['btn', 'btn-'.$variant, 'btn-sm' => $size === 'sm'];
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>
        @if ($icon)<x-icon :name="$icon" />@endif
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}>
        @if ($icon)<x-icon :name="$icon" />@endif
        {{ $slot }}
    </button>
@endif
