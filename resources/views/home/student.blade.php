<x-layouts.app title="Home">
    <section class="hero hero-compact animate-enter">
        <div class="hero-pattern" aria-hidden="true"></div>
        <div class="relative flex flex-wrap items-end justify-between gap-6">
            <div class="min-w-0">
                <p class="hero-eyebrow">{{ $greeting }}</p>
                <h1 class="hero-title">{{ $user->firstName() }}</h1>
                <div class="mt-4 flex flex-wrap gap-2">
                    <span class="hero-chip">{{ $user->level->label() }}</span>
                    <span class="hero-chip">{{ $user->department->name }}</span>
                    <span class="hero-chip">{{ $user->role->label() }}</span>
                </div>
            </div>
            <form method="GET" action="{{ route('library.index') }}" role="search" class="hero-search">
                <label for="home-search" class="sr-only">Search the library</label>
                <x-icon name="search" class="pointer-events-none absolute top-1/2 left-4 -translate-y-1/2 text-muted" />
                <input id="home-search" name="q" type="search" placeholder="Search books, courses, authors" enterkeyhint="search" class="field-input pl-11">
            </form>
        </div>
    </section>

    <nav aria-label="Shortcuts" class="mt-6">
        <ul class="stagger grid grid-cols-2 gap-3 md:grid-cols-4">
            <li><a href="{{ route('library.index') }}" class="shortcut"><x-icon-tile name="library-big" tone="green" /><span><strong>Library</strong><small>All books</small></span></a></li>
            <li><a href="{{ route('bookmarks.index') }}" class="shortcut"><x-icon-tile name="bookmark" tone="yellow" /><span><strong>Saved</strong><small>{{ $savedCount }} {{ \Illuminate\Support\Str::plural('book', $savedCount) }}</small></span></a></li>
            <li><a href="{{ route('profile.edit') }}" class="shortcut"><x-icon-tile name="user" tone="blue" /><span><strong>Profile</strong><small>Your details</small></span></a></li>
            <li><a href="{{ route('settings') }}" class="shortcut"><x-icon-tile name="settings" tone="violet" /><span><strong>Settings</strong><small>Security</small></span></a></li>
        </ul>
    </nav>

    <div class="mt-10 space-y-10 md:mt-12">
        <x-section title="New for {{ $user->level->label() }}" description="Recently added for your level in {{ $user->department->name }}." icon="sparkles" tone="yellow">
            <x-slot:action>
                <a href="{{ route('library.index', ['level' => $user->level->value]) }}" class="section-link">See all <x-icon name="arrow-right" /></a>
            </x-slot:action>

            @if ($forYou->isEmpty())
                <x-empty-state icon="library-big" title="Nothing new for your level yet" text="Books shared for {{ $user->level->label() }} will show up here.">
                    <x-button href="{{ route('library.index') }}" variant="secondary" size="sm">Browse everything</x-button>
                </x-empty-state>
            @else
                <ul class="shelf">
                    @foreach ($forYou as $book)
                        <li><x-book-card :book="$book" :saved="isset($savedIds[$book->id])" /></li>
                    @endforeach
                </ul>
            @endif
        </x-section>

        <x-section title="Saved books" description="Your shortlist for revision." icon="bookmark" tone="green">
            <x-slot:action>
                @if ($saved->isNotEmpty())
                    <a href="{{ route('bookmarks.index') }}" class="section-link">See all <x-icon name="arrow-right" /></a>
                @endif
            </x-slot:action>

            @if ($saved->isEmpty())
                <x-empty-state icon="bookmark" title="Nothing saved yet" text="Tap the bookmark on any book to keep it here.">
                    <x-button href="{{ route('library.index') }}" variant="secondary" size="sm" icon="library-big">Browse the library</x-button>
                </x-empty-state>
            @else
                <ul class="shelf">
                    @foreach ($saved as $book)
                        <li><x-book-card :book="$book" :saved="true" /></li>
                    @endforeach
                </ul>
            @endif
        </x-section>
    </div>
</x-layouts.app>
