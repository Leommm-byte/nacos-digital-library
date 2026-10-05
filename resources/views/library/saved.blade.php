<x-layouts.app title="Saved books">
    <x-page-header title="Saved books">
        <x-slot:meta><span data-saved-count>{{ $books->total() }}</span> saved for revision</x-slot:meta>
    </x-page-header>

    <x-empty-state icon="bookmark" tone="yellow" title="Nothing saved yet" text="Tap the bookmark on any book to keep it here." :class="$books->isNotEmpty() ? 'hidden' : ''" data-saved-empty>
        <x-button href="{{ route('library.index') }}" variant="secondary" size="sm" icon="library-big">Browse the library</x-button>
    </x-empty-state>

    @if ($books->isNotEmpty())
        <ul class="stagger grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 md:grid-cols-4 xl:grid-cols-6">
            @foreach ($books as $book)
                <li class="flex" data-saved-item><x-book-card :book="$book" :saved="true" class="w-full" /></li>
            @endforeach
        </ul>

        {{ $books->links('partials.pagination') }}
    @endif
</x-layouts.app>
