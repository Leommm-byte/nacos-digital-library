<x-layouts.app title="Your uploads">
    <x-page-header title="Your uploads" subtitle="Books you've shared and where they are in review.">
        <x-slot:actions>
            <x-button href="{{ route('uploads.create') }}" icon="upload">Upload a book</x-button>
        </x-slot:actions>
    </x-page-header>

    @if ($books->isEmpty())
        <x-empty-state icon="upload" tone="green" title="Nothing uploaded yet" text="Share a book or past questions with your classmates. Every upload is checked by a reviewer first.">
            <x-button href="{{ route('uploads.create') }}" icon="upload">Upload a book</x-button>
        </x-empty-state>
    @else
        <ul class="upload-list stagger">
            @foreach ($books as $book)
                <li>
                    <a href="{{ route('uploads.show', $book) }}" class="upload-row">
                        <span class="upload-row-cover"><x-book-cover :book="$book" /></span>
                        <span class="min-w-0 flex-1">
                            <span class="upload-row-title">{{ $book->title }}</span>
                            <span class="upload-row-meta">{{ $book->author }} · {{ $book->level->label() }} · {{ $book->created_at->timezone(config('app.display_timezone'))->format('j M Y') }}</span>
                        </span>
                        <x-upload-status :status="$book->status" class="shrink-0" />
                        <x-icon name="chevron-right" class="hidden shrink-0 text-muted sm:block" />
                    </a>
                </li>
            @endforeach
        </ul>

        {{ $books->links('partials.pagination') }}
    @endif
</x-layouts.app>
