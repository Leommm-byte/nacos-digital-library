<x-layouts.reader :title="$book->title">
    <div class="reader" @if ($fileUrl) data-reader @endif
        data-src="{{ $fileUrl }}"
        data-start-page="{{ $startPage }}"
        data-progress-url="{{ route('books.progress', $book) }}"
        data-token="{{ csrf_token() }}"
        data-assets="{{ asset('build/pdfjs') }}/"
        data-watermark="{{ $watermark }}"
        data-book="{{ $book->public_id }}">

        <header class="reader-bar">
            <a href="{{ route('library.show', $book) }}" class="btn btn-ghost btn-icon" aria-label="Back to the book's page">
                <x-icon name="arrow-left" />
            </a>
            <div class="min-w-0 flex-1">
                <h1 class="reader-title">{{ $book->title }}</h1>
                <p class="reader-subtitle">{{ $book->author }}</p>
            </div>
            @unless ($approved)
                <x-badge variant="accent" class="hidden sm:inline-flex">{{ $book->status->label() }}</x-badge>
            @endunless
            <x-theme-toggle />
        </header>

        @include('library.partials.reader-body', ['backUrl' => route('library.show', $book)])
    </div>
</x-layouts.reader>
