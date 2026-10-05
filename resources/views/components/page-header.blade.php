{{--
    Top of every page: optional back link and eyebrow, the title, a subtitle
    and actions on the right (stacked under the title on phones).

    <x-page-header title="Library" subtitle="36 books">
        <x-slot:actions><x-button …>Upload</x-button></x-slot:actions>
    </x-page-header>
--}}
@props(['title', 'subtitle' => null, 'eyebrow' => null, 'back' => null, 'backLabel' => 'Back'])

<header {{ $attributes->class(['page-header animate-enter']) }}>
    @if ($back)
        <a href="{{ $back }}" class="page-header-back"><x-icon name="chevron-left" /> {{ $backLabel }}</a>
    @endif
    <div class="flex flex-wrap items-end justify-between gap-x-6 gap-y-4">
        <div class="min-w-0">
            @if ($eyebrow)
                <p class="page-header-eyebrow">{{ $eyebrow }}</p>
            @endif
            <h1 class="page-header-title">{{ $title }}</h1>
            @if ($subtitle || isset($meta))
                <p class="page-header-subtitle">{{ $subtitle }}{{ $meta ?? '' }}</p>
            @endif
        </div>
        @isset($actions)
            <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
        @endisset
    </div>
</header>
