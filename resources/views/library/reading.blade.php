<x-layouts.app title="Books read">
    <x-page-header title="Books read">
        <x-slot:meta>{{ number_format($counts['all']) }} {{ \Illuminate\Support\Str::plural('book', $counts['all']) }} opened · {{ number_format($counts['finished']) }} finished</x-slot:meta>
    </x-page-header>

    @if ($counts['all'] === 0)
        <x-empty-state icon="book-open" tone="green" title="No books opened yet" text="Books you read appear here, with the page you stopped on.">
            <x-button href="{{ route('library.index') }}" variant="secondary" size="sm" icon="library-big">Browse the library</x-button>
        </x-empty-state>
    @else
        <nav class="review-tabs" aria-label="Show">
            @foreach (\App\Http\Controllers\Library\ReadingController::SHOWS as $value => $label)
                <a href="{{ route('reading.index', $value === 'all' ? [] : ['show' => $value]) }}"
                    class="review-tab" @if ($show === $value) aria-current="page" @endif>
                    {{ $label }} <span class="review-tab-count">{{ number_format($counts[$value]) }}</span>
                </a>
            @endforeach
        </nav>

        @if ($reading->isEmpty())
            <x-empty-state class="mt-6" icon="book-open" tone="green"
                :title="$show === 'finished' ? 'Nothing finished yet' : 'Nothing in progress'"
                :text="$show === 'finished' ? 'Books you read to the last page appear here.' : 'Every book you opened is finished.'" />
        @else
            <ul class="stagger mt-6 grid grid-cols-1 gap-3 lg:grid-cols-2">
                @foreach ($reading as $progress)
                    @php($done = $progress->completed_at !== null)
                    <li>
                        <a href="{{ route('books.read', $progress->book) }}" class="continue-card">
                            <span class="continue-cover"><x-book-cover :book="$progress->book" /></span>
                            <span class="min-w-0 flex-1">
                                <span class="continue-title">{{ $progress->book->title }}</span>
                                <span class="continue-meta">
                                    @if ($done)
                                        Finished
                                    @else
                                        Page {{ number_format($progress->current_page) }}@if ($progress->book->page_count) of {{ number_format($progress->book->page_count) }}@endif
                                    @endif
                                    · <time datetime="{{ $progress->last_read_at->toIso8601String() }}">{{ $progress->last_read_at->diffForHumans() }}</time>
                                </span>
                                <progress max="100" value="{{ $done ? 100 : $progress->progress_percent }}" class="continue-bar" aria-label="{{ $done ? 100 : $progress->progress_percent }}% read">{{ $done ? 100 : $progress->progress_percent }}%</progress>
                            </span>
                            @if ($done)
                                <x-badge variant="primary" class="shrink-0"><x-icon name="circle-check" /> Done</x-badge>
                            @endif
                            <x-icon name="chevron-right" class="shrink-0 text-muted" />
                        </a>
                    </li>
                @endforeach
            </ul>

            {{ $reading->links('partials.pagination') }}
        @endif
    @endif
</x-layouts.app>
