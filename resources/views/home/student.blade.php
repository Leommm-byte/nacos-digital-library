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
                <input id="home-search" name="q" type="search" placeholder="Search the library" enterkeyhint="search" class="field-input pl-11">
            </form>
        </div>
    </section>

    @include('partials.install-banner')

    {{-- At a glance; each tile leads to its page. --}}
    <nav aria-label="Your numbers" class="mt-6">
        <ul class="stagger grid grid-cols-2 gap-3 md:grid-cols-4">
            <li><a href="{{ route('reading.index') }}" class="shortcut"><x-icon-tile name="book-open" tone="green" />
                <span><strong>{{ number_format($stats['opened']) }} {{ \Illuminate\Support\Str::plural('book', $stats['opened']) }} read</strong><small>{{ $stats['finished'] }} finished</small></span></a></li>
            <li><a href="{{ route('bookmarks.index') }}" class="shortcut"><x-icon-tile name="bookmark" tone="yellow" />
                <span><strong>{{ number_format($stats['saved']) }} saved</strong><small>For revision</small></span></a></li>
            <li><a href="{{ route('uploads.index') }}" class="shortcut"><x-icon-tile name="upload" tone="blue" />
                <span><strong>{{ number_format($stats['uploads']) }} {{ \Illuminate\Support\Str::plural('upload', $stats['uploads']) }}</strong><small>{{ $stats['approved'] }} in the library</small></span></a></li>
            <li><a href="{{ route('notifications.index') }}" class="shortcut"><x-icon-tile name="bell" tone="violet" />
                <span><strong>{{ $stats['unread'] ? $stats['unread'].' new' : 'No new' }}</strong><small>Notifications</small></span></a></li>
        </ul>
    </nav>

    <div class="dashboard mt-10 md:mt-12">
        <div class="min-w-0 space-y-10">
            @if ($continue->isNotEmpty())
                <x-section title="Continue reading" description="Pick up where you stopped." icon="book-open" tone="green">
                    <x-slot:action>
                        <a href="{{ route('reading.index') }}" class="section-link">See all <x-icon name="arrow-right" /></a>
                    </x-slot:action>
                    <ul class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        @foreach ($continue as $progress)
                            <li>
                                <a href="{{ route('books.read', $progress->book) }}" class="continue-card">
                                    <span class="continue-cover"><x-book-cover :book="$progress->book" /></span>
                                    <span class="min-w-0 flex-1">
                                        <span class="continue-title">{{ $progress->book->title }}</span>
                                        <span class="continue-meta">Page {{ number_format($progress->current_page) }}@if ($progress->book->page_count) of {{ number_format($progress->book->page_count) }}@endif</span>
                                        <progress max="100" value="{{ $progress->progress_percent }}" class="continue-bar" aria-label="{{ $progress->progress_percent }}% read">{{ $progress->progress_percent }}%</progress>
                                    </span>
                                    <x-icon name="chevron-right" class="shrink-0 text-muted" />
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </x-section>
            @endif

            <x-section title="Recommended for you" description="New for {{ $user->level->label() }}, from {{ $user->department->name }} first." icon="sparkles" tone="yellow">
                <x-slot:action>
                    <a href="{{ route('library.index', ['level' => $user->level->value]) }}" class="section-link">See all <x-icon name="arrow-right" /></a>
                </x-slot:action>

                @if ($recommended->isEmpty())
                    <x-empty-state icon="library-big" title="Nothing new for your level yet" text="Books shared for {{ $user->level->label() }} will show up here.">
                        <x-button href="{{ route('library.index') }}" variant="secondary" size="sm">Browse everything</x-button>
                    </x-empty-state>
                @else
                    <ul class="shelf">
                        @foreach ($recommended as $book)
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

        <aside class="min-w-0 space-y-6">
            @foreach ($elections as $election)
                <div class="dashboard-card dashboard-card-vote">
                    <div class="flex items-center justify-between gap-3">
                        <span class="election-live"><span class="live-dot" aria-hidden="true"></span> Voting open</span>
                        @include('elections.partials.countdown', ['ends' => $election->ends_at])
                    </div>
                    <h2 class="mt-3 text-base">{{ $election->title }}</h2>
                    <p class="mt-1 text-sm text-muted">You haven't voted yet. It takes a minute, and your ballot is secret.</p>
                    <x-button href="{{ route('elections.show', $election) }}" size="sm" icon="vote" class="mt-4 w-full">Vote now</x-button>
                </div>
            @endforeach

            @if ($waiting !== null)
                <div class="dashboard-card dashboard-card-accent">
                    <div class="flex items-center gap-3">
                        <x-icon-tile name="shield-check" tone="yellow" size="sm" />
                        <div class="min-w-0">
                            <h2 class="text-base">{{ $waiting ? $waiting.' '.\Illuminate\Support\Str::plural('upload', $waiting).' waiting' : 'No uploads waiting' }}</h2>
                            <p class="text-sm text-muted">{{ $waiting ? 'Oldest first. Students are waiting to hear back.' : 'You\'re all caught up on reviews.' }}</p>
                        </div>
                    </div>
                    @if ($waiting)
                        <x-button href="{{ route('review.index') }}" size="sm" class="mt-4 w-full">Review uploads</x-button>
                    @endif
                </div>
            @endif

            <section class="dashboard-card" aria-labelledby="announcements-heading">
                <div class="flex items-center justify-between gap-3">
                    <h2 id="announcements-heading" class="flex items-center gap-2 text-base"><x-icon name="bell" class="text-muted" /> Announcements</h2>
                    @if ($announcements !== [])
                        <a href="{{ route('announcements.index') }}" class="section-link">See all</a>
                    @endif
                </div>
                @if ($announcements === [])
                    <p class="mt-3 text-sm text-muted">No announcements right now. News from NACOS will appear here.</p>
                @else
                    <ul class="announcement-list">
                        @foreach ($announcements as $announcement)
                            <li>
                                <h3 class="announcement-title">{{ $announcement['title'] }}</h3>
                                <p class="announcement-body">{{ $announcement['body'] }}</p>
                                <time class="announcement-date" datetime="{{ $announcement['date'] }}">{{ \Illuminate\Support\Carbon::parse($announcement['date'])->timezone(config('app.display_timezone'))->format('j M Y') }}</time>
                            </li>
                        @endforeach
                    </ul>
                @endif
                @can('manage-announcements')
                    <a href="{{ route('announcements.manage') }}" class="section-link mt-4">Manage announcements <x-icon name="arrow-right" /></a>
                @endcan
            </section>

            <section class="dashboard-card" aria-labelledby="profile-heading">
                <div class="flex items-center justify-between gap-3">
                    <h2 id="profile-heading" class="flex items-center gap-2 text-base"><x-icon name="user" class="text-muted" /> Your details</h2>
                    <a href="{{ route('profile.edit') }}" class="section-link">Edit</a>
                </div>
                <dl class="profile-summary">
                    <div><dt>Matric number</dt><dd>{{ $user->matric_number }}</dd></div>
                    <div><dt>Class</dt><dd>{{ $user->level->label() }} · {{ $user->programme->label() }}</dd></div>
                    <div><dt>Department</dt><dd>{{ $user->department->name }}</dd></div>
                    <div><dt>Member since</dt><dd>{{ $user->created_at->timezone(config('app.display_timezone'))->format('F Y') }}</dd></div>
                </dl>
            </section>

            <section class="dashboard-card" aria-labelledby="activity-heading">
                <h2 id="activity-heading" class="flex items-center gap-2 text-base"><x-icon name="clock" class="text-muted" /> Recent activity</h2>
                @if ($activity === [])
                    <p class="mt-3 text-sm text-muted">What you do here will show up in this list.</p>
                @else
                    <ol class="activity-list">
                        @foreach ($activity as $item)
                            <li>
                                <x-icon :name="$item['icon']" class="activity-icon" />
                                <span class="min-w-0">
                                    <span class="block">{{ $item['text'] }}</span>
                                    <time class="activity-time" datetime="{{ $item['at']->toIso8601String() }}">{{ $item['at']->diffForHumans() }}</time>
                                </span>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>
        </aside>
    </div>
</x-layouts.app>
