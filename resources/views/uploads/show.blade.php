@php
    $file = $book->currentFile;
    $approved = $book->status === \App\Enums\BookStatus::Approved;
    $pending = $book->status === \App\Enums\BookStatus::Pending;
    $decision = $book->reviews->first();
@endphp

<x-layouts.app :title="$book->title">
    <nav aria-label="Breadcrumb">
        <a href="{{ route('uploads.index') }}" class="page-header-back"><x-icon name="chevron-left" /> Your uploads</a>
    </nav>

    @if ($justUploaded)
        <div class="upload-done animate-enter">
            <x-icon-tile name="circle-check" tone="green" size="lg" />
            <div>
                <h1 class="page-header-title">Thanks for sharing!</h1>
                <p class="mt-1 text-muted">"{{ $book->title }}" is waiting for review. We'll add it to the library once a reviewer approves it.</p>
            </div>
        </div>
    @endif

    {{-- The reviewer's decision and note, when there is one. --}}
    @if ($decision && in_array($book->status, [\App\Enums\BookStatus::ChangesRequested, \App\Enums\BookStatus::Rejected], true))
        <div @class(['review-note mt-6', 'review-note-warning' => $book->status === \App\Enums\BookStatus::ChangesRequested, 'review-note-danger' => $book->status === \App\Enums\BookStatus::Rejected])>
            <x-icon-tile :name="$book->status === \App\Enums\BookStatus::Rejected ? 'x' : 'triangle-alert'" :tone="$book->status === \App\Enums\BookStatus::Rejected ? 'red' : 'yellow'" />
            <div class="min-w-0">
                <h2 class="text-base">{{ $book->status === \App\Enums\BookStatus::Rejected ? 'This upload wasn\'t approved' : 'A reviewer asked for changes' }}</h2>
                @if ($decision->comment)
                    <blockquote class="mt-2">{{ $decision->comment }}</blockquote>
                @endif
                @if ($book->status === \App\Enums\BookStatus::ChangesRequested)
                    <p class="mt-2 text-sm text-muted">Make the changes, then save to send it back for review.</p>
                @endif
            </div>
        </div>
    @endif

    <div class="mt-8 grid gap-6 sm:grid-cols-[minmax(0,10rem)_1fr] sm:gap-8">
        <div class="mx-auto w-32 sm:w-full">
            <x-book-cover :book="$book" eager class="w-full shadow-pop" />
        </div>

        <div class="min-w-0">
            @unless ($justUploaded)
                <h1 class="page-header-title">{{ $book->title }}</h1>
            @else
                <h2 class="text-xl">{{ $book->title }}</h2>
            @endunless
            <p class="mt-1 text-muted">{{ $book->author }}</p>

            <div class="mt-4 flex flex-wrap gap-2">
                <x-upload-status :status="$book->status" />
                <x-badge>{{ $book->level->label() }}</x-badge>
                <x-badge>{{ $book->department->name }}</x-badge>
            </div>

            <dl class="book-facts">
                <div><dt><x-icon :name="$file?->source === \App\Enums\BookSource::Scan ? 'camera' : 'file-text'" /> Uploaded as</dt><dd>{{ $file?->source === \App\Enums\BookSource::Scan ? 'Photos of pages' : 'PDF' }}</dd></div>
                @if ($book->page_count)
                    <div><dt><x-icon name="book-open" /> Pages</dt><dd>{{ number_format($book->page_count) }}</dd></div>
                @endif
                @if ($file)
                    <div><dt><x-icon name="file-up" /> Size</dt><dd>{{ \Illuminate\Support\Number::fileSize($file->size_bytes, precision: 1) }}</dd></div>
                @endif
                <div><dt><x-icon name="clock" /> Uploaded</dt><dd>{{ $book->created_at->timezone(config('app.display_timezone'))->format('j M Y, g:i a') }}</dd></div>
            </dl>

            {{-- Searchable text: read on the device, then (optionally) by AI. --}}
            <div class="upload-text" @if ($text['status'] === 'queued') data-upload-status="{{ route('uploads.status', $book) }}" @endif>
                <x-icon-tile name="scan-text" tone="violet" size="sm" />
                <div class="min-w-0 flex-1">
                    <p class="font-medium" data-upload-status-text>
                        @switch($text['status'])
                            @case('queued')
                                Making the text searchable: {{ $text['done'] }} of {{ $text['total'] }} pages
                                @break
                            @case('done')
                                The text of every page is searchable.
                                @break
                            @case('device')
                                The book's text is searchable.
                                @break
                            @default
                                No text could be read from this book, so it can be found by its title and author.
                        @endswitch
                    </p>
                    @if ($text['status'] === 'queued')
                        <progress class="mt-2 w-full" max="{{ max(1, $text['total']) }}" value="{{ $text['done'] }}" data-upload-status-bar>{{ $text['done'] }} of {{ $text['total'] }}</progress>
                        <p class="mt-1 text-sm text-muted">This runs in the background. You can leave this page.</p>
                    @endif
                </div>
            </div>

            <div class="mt-8 flex flex-wrap gap-3">
                @if ($approved)
                    <x-button href="{{ route('library.show', $book) }}" icon="book-open">See it in the library</x-button>
                @elseif ($file)
                    <x-button href="{{ route('books.read', $book) }}" icon="book-open" variant="secondary">Preview</x-button>
                @endif
                @can('update', $book)
                    <x-button href="{{ route('uploads.edit', $book) }}" :variant="$book->status === \App\Enums\BookStatus::ChangesRequested ? 'primary' : 'secondary'" icon="file-up">
                        {{ $book->status === \App\Enums\BookStatus::ChangesRequested ? 'Make changes' : 'Edit' }}
                    </x-button>
                @endcan
                <x-button href="{{ route('uploads.create') }}" icon="upload" :variant="$approved || $justUploaded ? 'primary' : 'secondary'">Upload another</x-button>
                @if ($justUploaded)
                    <x-button href="{{ route('home') }}" variant="ghost" icon="house">Go home</x-button>
                @endif
            </div>

            @if ($pending)
                <p class="mt-6 max-w-prose text-sm text-muted">Only you and reviewers can see this book until it's approved.</p>
            @endif

            @can('delete', $book)
                <form method="POST" action="{{ route('uploads.destroy', $book) }}" class="mt-6"
                    data-confirm="Delete &quot;{{ $book->title }}&quot;? This can't be undone.">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="link text-sm text-danger">Delete this upload</button>
                </form>
            @endcan
        </div>
    </div>
</x-layouts.app>
