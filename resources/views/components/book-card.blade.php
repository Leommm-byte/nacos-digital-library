{{-- heading: h2 where the cards sit right under the page title, h3 under a section. --}}
@props(['book', 'saved' => false, 'heading' => 'h3'])

<article {{ $attributes->class(['book-card']) }}>
    <a href="{{ route('library.show', $book) }}" class="block" tabindex="-1" aria-hidden="true">
        <x-book-cover :book="$book" class="w-full" />
    </a>
    <div class="flex flex-1 flex-col p-3">
        <{{ $heading }} class="book-card-title line-clamp-2 text-sm leading-snug">
            <a href="{{ route('library.show', $book) }}" class="book-card-link">{{ $book->title }}</a>
        </{{ $heading }}>
        <p class="mt-0.5 truncate text-xs text-muted">{{ $book->author }}</p>
        <div class="mt-auto flex items-center justify-between gap-2 pt-3">
            <x-badge>{{ $book->level->label() }}</x-badge>
            <x-bookmark-button :book="$book" :saved="$saved" compact />
        </div>
    </div>
</article>
