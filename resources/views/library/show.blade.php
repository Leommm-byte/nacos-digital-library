@php
    $approved = $book->status === \App\Enums\BookStatus::Approved;
@endphp

<x-layouts.app :title="$book->title" :description="'By '.$book->author.' · '.$book->level->label().' '.$book->department->name">
    <nav aria-label="Breadcrumb" class="mb-4 text-sm">
        <a href="{{ route('library.index') }}" class="link inline-flex items-center gap-1"><x-icon name="chevron-left" /> Library</a>
    </nav>

    <div class="animate-enter grid gap-6 sm:grid-cols-[minmax(0,14rem)_1fr] sm:gap-8">
        <div class="mx-auto w-44 sm:w-full">
            <x-book-cover :book="$book" eager class="w-full shadow-pop" />
        </div>

        <div class="min-w-0">
            @unless ($approved)
                <x-alert type="warning" class="mb-4">This book is {{ str_replace('_', ' ', $book->status->value) }} and is only visible to you and reviewers.</x-alert>
            @endunless

            <h1 class="text-2xl sm:text-3xl">{{ $book->title }}</h1>
            <p class="mt-1 text-lg text-muted">{{ $book->author }}</p>

            <div class="mt-4 flex flex-wrap gap-2">
                <x-badge variant="primary">{{ $book->level->label() }}</x-badge>
                <x-badge>{{ $book->department->name }}</x-badge>
            </div>

            <div class="mt-6 flex flex-wrap gap-3">
                @if (Route::has('books.read'))
                    <x-button href="{{ route('books.read', $book) }}" icon="book-open">Read</x-button>
                @else
                    <x-button type="button" icon="book-open" disabled title="The reader arrives in the next update">Read</x-button>
                @endif
                @if ($approved)
                    <x-bookmark-button :book="$book" :saved="isset($bookmarked[$book->id])" />
                @endif
            </div>

            @if ($book->description)
                <div class="mt-8 max-w-prose">
                    <h2 class="text-base">About this book</h2>
                    <p class="mt-2 whitespace-pre-line text-muted">{{ $book->description }}</p>
                </div>
            @endif

            <dl class="mt-8 grid max-w-md grid-cols-2 gap-x-6 gap-y-3 text-sm">
                @if ($book->page_count)
                    <div><dt class="text-muted">Pages</dt><dd class="font-medium">{{ number_format($book->page_count) }}</dd></div>
                @endif
                <div><dt class="text-muted">Reads</dt><dd class="font-medium">{{ number_format($book->views_count) }}</dd></div>
                @if ($book->uploader)
                    <div><dt class="text-muted">Shared by</dt><dd class="font-medium">{{ $book->uploader->fullname }}</dd></div>
                @endif
                @if ($book->approved_at)
                    <div><dt class="text-muted">Added</dt><dd class="font-medium">{{ $book->approved_at->timezone(config('app.display_timezone'))->format('j M Y') }}</dd></div>
                @endif
            </dl>
        </div>
    </div>

    @if ($related->isNotEmpty())
        <section class="mt-12" aria-labelledby="related-heading" data-reveal>
            <h2 id="related-heading" class="text-lg">More for {{ $book->level->label() }} {{ $book->department->name }}</h2>
            <ul class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4 sm:gap-4">
                @foreach ($related as $item)
                    <li class="flex"><x-book-card :book="$item" :saved="isset($bookmarked[$item->id])" class="w-full" /></li>
                @endforeach
            </ul>
        </section>
    @endif
</x-layouts.app>
