<x-layouts.reader :title="$book->title">
    <div class="reader" @if ($fileUrl) data-reader @endif
        data-src="{{ $fileUrl }}"
        data-start-page="{{ $startPage }}"
        data-progress-url="{{ route('books.progress', $book) }}"
        data-token="{{ csrf_token() }}"
        data-assets="{{ asset('build/pdfjs') }}/"
        data-watermark="{{ $watermark }}">

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

        <main id="main" class="reader-stage" data-reader-stage tabindex="-1">
            @if ($fileUrl)
                <div class="reader-loading" data-reader-loading>
                    <div class="reader-skeleton" aria-hidden="true"></div>
                    <p class="text-sm text-muted">Opening the book…</p>
                    <div class="reader-progress" aria-hidden="true"><span data-reader-bar></span></div>
                </div>

                <div class="reader-page" data-reader-page hidden></div>

                <div class="reader-message" data-reader-error hidden>
                    <x-icon-tile name="circle-alert" tone="red" size="lg" />
                    <h2 class="mt-4 text-lg">Unable to load this book</h2>
                    <p class="mt-1.5 max-w-sm text-sm text-muted" data-reader-error-text>Check your connection and try again.</p>
                    <div class="mt-5 flex flex-wrap justify-center gap-2">
                        <x-button type="button" icon="rotate-ccw" data-reader-retry>Try again</x-button>
                        <x-button href="{{ route('library.show', $book) }}" variant="secondary">Back to the book</x-button>
                    </div>
                </div>

                <noscript>
                    <div class="reader-message">
                        <h2 class="text-lg">The reader needs JavaScript</h2>
                        <p class="mt-1.5 text-sm text-muted">Turn on JavaScript in your browser to read this book.</p>
                    </div>
                </noscript>
            @else
                <div class="reader-message">
                    <x-icon-tile name="file-text" tone="neutral" size="lg" />
                    <h2 class="mt-4 text-lg">This book isn't ready to read yet</h2>
                    <p class="mt-1.5 max-w-sm text-sm text-muted">Its file hasn't been added. Please check back later.</p>
                    <div class="mt-5">
                        <x-button href="{{ route('library.show', $book) }}" variant="secondary" icon="arrow-left">Back to the book</x-button>
                    </div>
                </div>
            @endif
        </main>

        @if ($fileUrl)
            <nav class="reader-toolbar" aria-label="Reader controls">
                <div class="reader-group">
                    <button type="button" class="btn btn-ghost btn-icon" data-reader-prev data-reader-control disabled aria-label="Previous page">
                        <x-icon name="chevron-left" />
                    </button>
                    <form class="reader-jump" data-reader-jump>
                        <label for="reader-page" class="sr-only">Page number</label>
                        <input id="reader-page" type="number" inputmode="numeric" min="1" value="{{ $startPage }}" enterkeyhint="go"
                            class="reader-input" data-reader-input data-reader-control disabled>
                        <span class="text-muted">of <span data-reader-total>–</span></span>
                    </form>
                    <button type="button" class="btn btn-ghost btn-icon" data-reader-next data-reader-control disabled aria-label="Next page">
                        <x-icon name="chevron-right" />
                    </button>
                </div>

                <div class="reader-group">
                    <button type="button" class="btn btn-ghost btn-icon" data-reader-zoom-out data-reader-control disabled aria-label="Zoom out">
                        <x-icon name="zoom-out" />
                    </button>
                    <button type="button" class="reader-zoom" data-reader-zoom-reset data-reader-control disabled aria-label="Reset zoom">100%</button>
                    <button type="button" class="btn btn-ghost btn-icon" data-reader-zoom-in data-reader-control disabled aria-label="Zoom in">
                        <x-icon name="zoom-in" />
                    </button>
                </div>
            </nav>

            <p class="sr-only" role="status" aria-live="polite" data-reader-status></p>
            <p class="reader-print-notice">Printing is turned off for library books.</p>
        @endif
    </div>
</x-layouts.reader>
