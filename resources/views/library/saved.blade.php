<x-layouts.app title="Saved books">
    <div class="animate-enter">
        <h1 class="text-2xl sm:text-3xl">Saved books</h1>
        <p class="mt-1 text-muted"><span data-saved-count>{{ $books->total() }}</span> saved</p>
    </div>

    <div @class(['animate-enter mt-10 flex flex-col items-center rounded-xl border border-dashed border-border px-6 py-14 text-center', 'hidden' => $books->isNotEmpty()]) data-saved-empty>
        <x-icon name="bookmark" class="size-10 text-muted" />
        <h2 class="mt-4 text-lg">Nothing saved yet</h2>
        <p class="mt-1 max-w-sm text-sm text-muted">Tap the bookmark on any book to keep it here.</p>
        <x-button href="{{ route('library.index') }}" variant="secondary" size="sm" class="mt-5" icon="library-big">Browse the library</x-button>
    </div>

    @if ($books->isNotEmpty())
        <ul class="stagger mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 md:grid-cols-4 xl:grid-cols-6">
            @foreach ($books as $book)
                <li class="flex" data-saved-item><x-book-card :book="$book" :saved="true" class="w-full" /></li>
            @endforeach
        </ul>

        {{ $books->links('partials.pagination') }}
    @endif
</x-layouts.app>
