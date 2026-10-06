@php
    $file = $book->currentFile;
@endphp

<x-layouts.app :title="'Review: '.$book->title">
    <nav aria-label="Breadcrumb">
        <a href="{{ route('review.index') }}" class="page-header-back"><x-icon name="chevron-left" /> Review uploads</a>
    </nav>

    @error('action')
        <x-alert type="error" class="mb-6">{{ $message }}</x-alert>
    @enderror

    <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start">
        <div class="min-w-0 space-y-8">
            <div class="flex gap-5">
                <div class="w-24 shrink-0 sm:w-32"><x-book-cover :book="$book" eager class="w-full shadow-pop" /></div>
                <div class="min-w-0">
                    <h1 class="page-header-title">{{ $book->title }}</h1>
                    <p class="mt-1 text-muted">{{ $book->author }}</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <x-upload-status :status="$book->status" />
                        <x-badge>{{ $book->level->label() }}</x-badge>
                        <x-badge>{{ $book->department->name }}</x-badge>
                    </div>
                </div>
            </div>

            <dl class="book-facts mt-0">
                <div><dt><x-icon name="user" /> Uploaded by</dt><dd>{{ $book->uploader?->fullname ?? 'Unknown' }}@if ($book->uploader)<span class="block text-sm font-normal text-muted">{{ $book->uploader->matric_number }}</span>@endif</dd></div>
                <div><dt><x-icon name="clock" /> Uploaded</dt><dd>{{ $book->created_at->timezone(config('app.display_timezone'))->format('j M Y, g:i a') }}</dd></div>
                <div><dt><x-icon :name="$file?->source === \App\Enums\BookSource::Scan ? 'camera' : 'file-text'" /> File</dt><dd>{{ $file?->source === \App\Enums\BookSource::Scan ? 'Photos of pages' : 'PDF' }}@if ($file)<span class="block text-sm font-normal text-muted">{{ \Illuminate\Support\Number::fileSize($file->size_bytes, precision: 1) }}{{ $file->original_name ? ' · '.$file->original_name : '' }}</span>@endif</dd></div>
                <div><dt><x-icon name="book-open" /> Pages</dt><dd>{{ $book->page_count ? number_format($book->page_count) : 'Not counted' }}</dd></div>
            </dl>

            @if ($book->description)
                <div>
                    <h2 class="text-base">Description</h2>
                    <p class="mt-2 max-w-prose whitespace-pre-line text-muted">{{ $book->description }}</p>
                </div>
            @endif

            <x-section title="Preview" description="The first pages. Open the book to read all of it." icon="book-open">
                <x-slot:action>
                    @if ($fileUrl)
                        <a href="{{ route('books.read', $book) }}" class="section-link">Open the book <x-icon name="arrow-right" /></a>
                    @endif
                </x-slot:action>
                @if ($fileUrl)
                    <div class="review-preview" data-pdf-preview data-src="{{ $fileUrl }}" data-assets="{{ asset('build/pdfjs') }}/">
                        @for ($i = 0; $i < 4; $i++)
                            <div class="review-preview-page skeleton" aria-hidden="true"></div>
                        @endfor
                    </div>
                @else
                    <x-empty-state icon="file-text" title="No file" text="This upload has no file to preview." />
                @endif
            </x-section>

            <x-section title="History" icon="clock">
                <ol class="review-history">
                    @foreach ($book->reviews as $review)
                        <li @class(['review-history-'.$review->action->value])>
                            <p><strong>{{ $review->action->label() }}</strong> by {{ $review->reviewer?->fullname ?? 'someone' }}
                                <span class="text-muted">· {{ $review->created_at?->timezone(config('app.display_timezone'))->format('j M Y, g:i a') }}</span></p>
                            @if ($review->comment)
                                <blockquote>{{ $review->comment }}</blockquote>
                            @endif
                        </li>
                    @endforeach
                    <li><p><strong>Uploaded</strong> by {{ $book->uploader?->fullname ?? 'someone' }}
                        <span class="text-muted">· {{ $book->created_at->timezone(config('app.display_timezone'))->format('j M Y, g:i a') }}</span></p></li>
                </ol>
            </x-section>
        </div>

        <aside class="space-y-4 lg:sticky lg:top-24">
            <x-card class="space-y-4">
                <h2 class="text-lg">Decision</h2>

                @if ($canReview)
                    <form method="POST" action="{{ route('review.decide', $book) }}" class="space-y-4" novalidate>
                        @csrf
                        <fieldset class="review-decisions">
                            <legend class="sr-only">Decision</legend>
                            @foreach ([
                                ['approved', 'circle-check', 'green', 'Approve', 'Add it to the library'],
                                ['changes_requested', 'triangle-alert', 'yellow', 'Request changes', 'The uploader can fix and resubmit'],
                                ['rejected', 'x', 'red', 'Reject', 'Not suitable for the library'],
                            ] as [$value, $icon, $tone, $label, $hint])
                                <label class="review-decision">
                                    <input type="radio" name="action" value="{{ $value }}" class="sr-only" @checked(old('action', 'approved') === $value) required>
                                    <x-icon-tile :name="$icon" :tone="$tone" size="sm" />
                                    <span><strong>{{ $label }}</strong><small>{{ $hint }}</small></span>
                                </label>
                            @endforeach
                        </fieldset>
                        <div>
                            <label for="comment" class="field-label">Note to the uploader</label>
                            <textarea id="comment" name="comment" rows="4" maxlength="1000" class="field-input field-textarea"
                                @error('comment') aria-invalid="true" aria-describedby="comment-error" @enderror
                                placeholder="Optional when approving. Say what to fix, or why it can't be added.">{{ old('comment') }}</textarea>
                            @error('comment')
                                <p id="comment-error" class="field-error">{{ $message }}</p>
                            @enderror
                        </div>
                        <x-button class="w-full" icon="shield-check">Confirm decision</x-button>
                        <p class="text-sm text-muted">The uploader is told straight away, with your note.</p>
                    </form>
                @elseif ($ownUpload && $book->status === \App\Enums\BookStatus::Pending)
                    <x-alert type="info">This is your own upload, so another reviewer has to decide it.</x-alert>
                @else
                    <p class="text-sm text-muted">
                        @switch($book->status)
                            @case(\App\Enums\BookStatus::Approved)
                                This book is in the library.
                                @break
                            @case(\App\Enums\BookStatus::ChangesRequested)
                                Waiting for the uploader to make changes and resubmit.
                                @break
                            @default
                                This upload has been decided. See its history.
                        @endswitch
                    </p>
                @endif

                @if ($nextPending)
                    <a href="{{ route('review.show', $nextPending) }}" class="section-link">Skip to the next upload <x-icon name="arrow-right" /></a>
                @endif
            </x-card>

            @if ($canDelete)
                <form method="POST" action="{{ route('uploads.destroy', $book) }}" data-confirm="Delete &quot;{{ $book->title }}&quot; for good? Its file, cover, bookmarks and reading history are removed too.">
                    @csrf
                    @method('DELETE')
                    <x-button variant="ghost" class="w-full text-danger" icon="x">Delete this book</x-button>
                </form>
            @endif
        </aside>
    </div>
</x-layouts.app>
