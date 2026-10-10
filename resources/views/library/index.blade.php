<x-layouts.app title="Library" description="Books and past questions for NACOS YabaTech students.">
    <x-page-header title="Library" :subtitle="number_format($books->total()).' '.\Illuminate\Support\Str::plural('book', $books->total()).($filtering ? ' found' : ' for NACOS students')">
        <x-slot:actions>
            @if (Route::has('uploads.create'))
                <x-button href="{{ route('uploads.create') }}" variant="secondary" icon="upload">Upload</x-button>
            @endif
        </x-slot:actions>
    </x-page-header>

    <form method="GET" action="{{ route('library.index') }}" role="search" class="library-filters animate-enter" data-autosubmit>
        <div class="relative">
            <label for="q" class="sr-only">Search by title, author or topic</label>
            <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3.5 -translate-y-1/2 text-muted" />
            <input id="q" name="q" type="search" value="{{ $filters['q'] }}" placeholder="Search by title, author or topic" maxlength="100" enterkeyhint="search" class="field-input pl-11 min-h-12">
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <label class="sr-only" for="level">Level</label>
            <select id="level" name="level" class="field-input field-select w-auto min-h-12 text-sm">
                <option value="">All levels</option>
                @foreach ($levels as $value => $label)
                    <option value="{{ $value }}" @selected($filters['level'] === $value)>{{ $label }}</option>
                @endforeach
            </select>

            @if (count($departments) > 1)
                <label class="sr-only" for="department">Department</label>
                <select id="department" name="department" class="field-input field-select w-auto min-h-12 text-sm">
                    <option value="">All departments</option>
                    @foreach ($departments as $id => $name)
                        <option value="{{ $id }}" @selected($filters['department'] === $id)>{{ $name }}</option>
                    @endforeach
                </select>
            @endif

            <label class="sr-only" for="sort">Sort by</label>
            <select id="sort" name="sort" class="field-input field-select w-auto min-h-12 text-sm">
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
        <x-empty-state icon="book-x" class="mt-8" title="No books found" :text="$filtering ? 'Try fewer or different words, or another level.' : 'Nothing has been added yet. Check back soon.'">
            @if ($filtering)
                <x-button href="{{ route('library.index') }}" variant="secondary" size="sm">Show all books</x-button>
            @endif
        </x-empty-state>
    @else
        <ul class="stagger mt-8 grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 md:grid-cols-4 xl:grid-cols-6">
            @foreach ($books as $book)
                <li class="flex"><x-book-card :book="$book" :saved="isset($bookmarked[$book->id])" heading="h2" class="w-full" /></li>
            @endforeach
        </ul>

        {{ $books->links('partials.pagination') }}
    @endif
</x-layouts.app>
