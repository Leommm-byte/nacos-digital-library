<x-layouts.app title="Review uploads">
    <x-page-header title="Review uploads" :subtitle="($counts['pending'] ?? 0) === 0 ? 'Nothing is waiting for review.' : number_format($counts['pending']).' '.\Illuminate\Support\Str::plural('upload', $counts['pending']).' waiting, oldest first.'" />

    <nav class="review-tabs" aria-label="Status">
        @foreach (\App\Http\Controllers\Review\ReviewController::STATUSES as $value => $label)
            <a href="{{ route('review.index', array_filter([...$filters, 'status' => $value, 'page' => null])) }}"
                class="review-tab" @if ($filters['status'] === $value) aria-current="page" @endif>
                {{ $label }}
                @if ($value !== 'all')
                    <span class="review-tab-count">{{ number_format($counts[$value] ?? 0) }}</span>
                @endif
            </a>
        @endforeach
    </nav>

    <form method="GET" action="{{ route('review.index') }}" class="library-filters mt-4" data-autosubmit role="search">
        <input type="hidden" name="status" value="{{ $filters['status'] }}">
        <div class="relative min-w-0 flex-1">
            <label for="review-q" class="sr-only">Search by title or author</label>
            <x-icon name="search" class="pointer-events-none absolute top-1/2 left-4 -translate-y-1/2 text-muted" />
            <input id="review-q" name="q" type="search" value="{{ $filters['q'] }}" placeholder="Title or author" class="field-input pl-11">
        </div>
        <div class="flex gap-2">
            <label for="review-level" class="sr-only">Level</label>
            <select id="review-level" name="level" class="field-input field-select min-h-12 w-auto">
                <option value="">All levels</option>
                @foreach ($levels as $value => $label)
                    <option value="{{ $value }}" @selected($filters['level'] === $value)>{{ $label }}</option>
                @endforeach
            </select>
            @if (count($departments) > 1)
                <label for="review-department" class="sr-only">Department</label>
                <select id="review-department" name="department" class="field-input field-select min-h-12 w-auto">
                    <option value="">All departments</option>
                    @foreach ($departments as $id => $name)
                        <option value="{{ $id }}" @selected($filters['department'] === $id)>{{ $name }}</option>
                    @endforeach
                </select>
            @endif
        </div>
    </form>

    @if ($books->isEmpty())
        @if ($filters['status'] === 'pending' && $filters['q'] === '' && $filters['level'] === '' && ! $filters['department'])
            <x-empty-state icon="circle-check" tone="green" class="mt-8" title="All caught up" text="No uploads are waiting for review. New ones will appear here." />
        @else
            <x-empty-state icon="search" class="mt-8" title="No uploads match" text="Try another status or fewer filters." />
        @endif
    @else
        <ul class="upload-list stagger mt-6">
            @foreach ($books as $book)
                <li>
                    <a href="{{ route('review.show', $book) }}" class="upload-row">
                        <span class="upload-row-cover"><x-book-cover :book="$book" /></span>
                        <span class="min-w-0 flex-1">
                            <span class="upload-row-title">{{ $book->title }}</span>
                            <span class="upload-row-meta">{{ $book->author }} · {{ $book->level->label() }} · {{ $book->department->name }}</span>
                            <span class="upload-row-meta">
                                {{ $book->uploader ? $book->uploader->fullname.' ('.$book->uploader->matric_number.')' : 'Unknown uploader' }}
                                · {{ $book->currentFile?->source === \App\Enums\BookSource::Scan ? 'Photos' : 'PDF' }}
                                · <time datetime="{{ $book->created_at->toIso8601String() }}">{{ $book->created_at->diffForHumans() }}</time>
                            </span>
                        </span>
                        <x-upload-status :status="$book->status" class="hidden shrink-0 sm:inline-flex" />
                        <x-icon name="chevron-right" class="shrink-0 text-muted" />
                    </a>
                </li>
            @endforeach
        </ul>

        {{ $books->links('partials.pagination') }}
    @endif
</x-layouts.app>
