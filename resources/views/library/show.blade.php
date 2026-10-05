@php
    $approved = $book->status === \App\Enums\BookStatus::Approved;
@endphp

<x-layouts.app :title="$book->title" :description="'By '.$book->author.' · '.$book->level->label().' '.$book->department->name">
    <nav aria-label="Breadcrumb">
        <a href="{{ route('library.index') }}" class="page-header-back"><x-icon name="chevron-left" /> Library</a>
    </nav>

    <div class="animate-enter grid gap-6 sm:grid-cols-[minmax(0,14rem)_1fr] sm:gap-8">
        <div class="mx-auto w-44 sm:w-full">
            <x-book-cover :book="$book" eager class="w-full shadow-pop" />
        </div>

        <div class="min-w-0">
            @unless ($approved)
                <x-alert type="warning" class="mb-4">This book is {{ str_replace('_', ' ', $book->status->value) }} and is only visible to you and reviewers.</x-alert>
            @endunless

            <h1 class="page-header-title">{{ $book->title }}</h1>
            <p class="mt-1 text-lg text-muted">{{ $book->author }}</p>

            <div class="mt-4 flex flex-wrap gap-2">
                <x-badge variant="primary">{{ $book->level->label() }}</x-badge>
                <x-badge>{{ $book->department->name }}</x-badge>
            </div>

            <div class="mt-6 flex flex-wrap gap-3">
                @if ($book->currentFile)
                    <x-button href="{{ route('books.read', $book) }}" icon="book-open">
                        @if ($progress && $progress->current_page > 1)
                            Continue reading <span class="font-normal opacity-80">· page {{ $progress->current_page }}</span>
                        @else
                            Read
                        @endif
                    </x-button>
                @else
                    <x-button type="button" icon="book-open" disabled>Not available yet</x-button>
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

            @if ($progress && $progress->progress_percent > 0)
                <div class="reading-meter">
                    <div class="flex items-center justify-between text-sm">
                        <span class="font-medium">{{ $progress->completed_at ? 'Finished' : 'Your progress' }}</span>
                        <span class="text-muted">{{ $progress->progress_percent }}%</span>
                    </div>
                    <progress max="100" value="{{ $progress->progress_percent }}" aria-label="Reading progress">{{ $progress->progress_percent }}%</progress>
                </div>
            @endif

            <dl class="book-facts">
                @if ($book->page_count)
                    <div><dt><x-icon name="file-text" /> Pages</dt><dd>{{ number_format($book->page_count) }}</dd></div>
                @endif
                <div><dt><x-icon name="book-open" /> Reads</dt><dd>{{ number_format($book->views_count) }}</dd></div>
                @if ($book->uploader)
                    <div><dt><x-icon name="user" /> Shared by</dt><dd>{{ $book->uploader->fullname }}</dd></div>
                @endif
                @if ($book->approved_at)
                    <div><dt><x-icon name="sparkles" /> Added</dt><dd>{{ $book->approved_at->timezone(config('app.display_timezone'))->format('j M Y') }}</dd></div>
                @endif
            </dl>
        </div>
    </div>

    @if ($related->isNotEmpty())
        <x-section class="mt-14" title="More for {{ $book->level->label() }} {{ $book->department->name }}" icon="library-big" data-reveal>
            <ul class="grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 md:grid-cols-4 xl:grid-cols-6">
                @foreach ($related as $item)
                    <li class="flex"><x-book-card :book="$item" :saved="isset($bookmarked[$item->id])" class="w-full" /></li>
                @endforeach
            </ul>
        </x-section>
    @endif
</x-layouts.app>
