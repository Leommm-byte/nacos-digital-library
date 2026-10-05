{{--
    <x-empty-state icon="book-x" title="No books found" text="Try different words.">
        <x-button …>Show all books</x-button>
    </x-empty-state>
--}}
@props(['icon' => 'info', 'title', 'text' => null, 'tone' => 'neutral'])

<div {{ $attributes->class(['empty-state animate-enter']) }}>
    <x-icon-tile :name="$icon" :tone="$tone" size="lg" />
    <h2 class="mt-4 text-lg">{{ $title }}</h2>
    @if ($text)
        <p class="mt-1.5 max-w-sm text-sm text-muted">{{ $text }}</p>
    @endif
    @if (trim($slot) !== '')
        <div class="mt-5 flex flex-wrap justify-center gap-2">{{ $slot }}</div>
    @endif
</div>
