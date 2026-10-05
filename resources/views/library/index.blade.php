<x-layouts.app title="Library" description="Books and past questions for NACOS YabaTech students.">
    <div class="animate-enter flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl sm:text-3xl">Library</h1>
            <p class="mt-1 text-muted">{{ number_format($books->total()) }} {{ \Illuminate\Support\Str::plural('book', $books->total()) }}{{ $filtering ? ' found' : '' }}</p>
        </div>
        @if (Route::has('uploads.create'))
            <x-button href="{{ route('uploads.create') }}" variant="secondary" icon="upload">Upload</x-button>
        @endif
    </div>

    <form method="GET" action="{{ route('library.index') }}" role="search" class="animate-enter mt-6 space-y-3" data-autosubmit>
        <div class="relative">
            <label for="q" class="sr-only">Search by title, author or topic</label>
            <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3.5 -translate-y-1/2 text-muted" />
            <input id="q" name="q" type="search" value="{{ $filters['q'] }}" placeholder="Search by title, author or topic" maxlength="100" enterkeyhint="search" class="field-input pl-11">
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <label class="sr-only" for="level">Level</label>
            <select id="level" name="level" class="field-input field-select w-auto min-h-10 py-1.5 text-sm">
                <option value="">All levels</option>
                @foreach ($levels as $value => $label)
                    <option value="{{ $value }}" @selected($filters['level'] === $value)>{{ $label }}</option>
                @endforeach
            </select>

            @if (count($departments) > 1)
                <label class="sr-only" for="department">Department</label>
                <select id="department" name="department" class="field-input field-select w-auto min-h-10 py-1.5 text-sm">
                    <option value="">All departments</option>
                    @foreach ($departments as $id => $name)
                        <option value="{{ $id }}" @selected($filters['department'] === $id)>{{ $name }}</option>
                    @endforeach
                </select>
            @endif

            <label class="sr-only" for="sort">Sort by</label>
            <select id="sort" name="sort" class="field-input field-select w-auto min-h-10 py-1.5 text-sm">
                @if ($filters['q'] !== '')
                    <option value="">Best match</option>
                @endif
                @foreach ($sorts as $value => $label)
                    <option value="{{ $value }}" @selected($filters['sort'] === $value)>{{ $label }}</option>
                @endforeach
            </select>

            <x-button size="sm" class="js-hidden">Search</x-button>

            @if ($filtering)
                <a href="{{ route('library.index') }}" class="link ml-1 text-sm">Clear</a>
            @endif
        </div>
    </form>

    @if ($books->isEmpty())
        <div class="animate-enter mt-10 flex flex-col items-center rounded-xl border border-dashed border-border px-6 py-14 text-center">
            <x-icon name="book-x" class="size-10 text-muted" />
            <h2 class="mt-4 text-lg">No books found</h2>
            <p class="mt-1 max-w-sm text-sm text-muted">
                @if ($filtering)
                    Try fewer or different words, or a different level.
                @else
                    Nothing has been added yet. Check back soon.
                @endif
            </p>
            @if ($filtering)
                <x-button href="{{ route('library.index') }}" variant="secondary" size="sm" class="mt-5">Show all books</x-button>
            @endif
        </div>
    @else
        <ul class="stagger mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 md:grid-cols-4 xl:grid-cols-6">
            @foreach ($books as $book)
                <li class="flex"><x-book-card :book="$book" :saved="isset($bookmarked[$book->id])" class="w-full" /></li>
            @endforeach
        </ul>

        {{ $books->links('partials.pagination') }}
    @endif
</x-layouts.app>
