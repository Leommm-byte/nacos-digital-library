@php
    $zone = config('app.display_timezone');
    $statuses = ['' => 'Any status'] + collect(\App\Enums\BookStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all();
    $departmentOptions = ['' => 'Any department'] + $departments;
@endphp

<x-layouts.admin title="Books">
    <x-page-header title="Books" :subtitle="number_format($books->total()).' '.\Illuminate\Support\Str::plural('book', $books->total()).' in the library and in review'" />

    <form method="GET" action="{{ route('admin.books.index') }}" class="filter-bar mb-6" data-autosubmit>
        <div class="filter-search">
            <label for="book-search" class="field-label">Search</label>
            <input id="book-search" name="q" type="search" value="{{ $search }}" placeholder="Title or author" class="field-input">
        </div>
        <x-select name="status" label="Status" :options="$statuses" :value="$filters['status'] ?? ''" />
        <x-select name="department" label="Department" :options="$departmentOptions" :value="$filters['department'] ?? ''" />
        <div class="filter-actions"><x-button variant="secondary" icon="search">Filter</x-button></div>
    </form>

    @if ($books->isEmpty())
        <x-empty-state icon="library-big" title="No books found" text="Try another title, or clear the filters.">
            <x-button href="{{ route('admin.books.index') }}" variant="secondary">Clear filters</x-button>
        </x-empty-state>
    @else
        <ul class="upload-list">
            @foreach ($books as $book)
                <li class="flex items-center gap-2">
                    <a href="{{ $book->status === \App\Enums\BookStatus::Approved ? route('library.show', $book) : route('review.show', $book) }}" class="upload-row min-w-0 flex-1">
                        <span class="upload-row-cover"><x-book-cover :book="$book" /></span>
                        <span class="min-w-0 flex-1">
                            <span class="upload-row-title">{{ $book->title }}</span>
                            <span class="upload-row-meta">{{ $book->level->label() }} · {{ $book->department->name }} · {{ $book->uploader?->fullname ?? 'Unknown' }} · {{ $book->created_at->timezone($zone)->format('j M Y') }}</span>
                        </span>
                        <x-upload-status :status="$book->status" class="shrink-0" />
                    </a>
                    <form method="POST" action="{{ route('admin.books.destroy', $book) }}" data-confirm="Delete “{{ $book->title }}” for good? Its file, cover, bookmarks and reading history go too. This can't be undone.">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="icon-btn icon-btn-danger" aria-label="Delete {{ $book->title }}"><x-icon name="trash-2" /></button>
                    </form>
                </li>
            @endforeach
        </ul>

        {{ $books->links('partials.pagination') }}
    @endif
</x-layouts.admin>
