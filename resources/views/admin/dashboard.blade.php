@php($zone = config('app.display_timezone'))

<x-layouts.admin title="Dashboard">
    <x-page-header title="Admin" :subtitle="$session ? 'Academic session '.$session : 'Run NACOS YabaTech: accounts, the library, elections and settings.'" />

    <nav aria-label="At a glance">
        <ul class="stagger grid grid-cols-2 gap-3 xl:grid-cols-4">
            <li><a href="{{ route('review.index') }}" class="shortcut"><x-icon-tile name="shield-check" tone="yellow" />
                <span><strong>{{ number_format($stats['pending']) }} waiting</strong><small>Uploads to review</small></span></a></li>
            <li><a href="{{ route('admin.books.index') }}" class="shortcut"><x-icon-tile name="library-big" tone="green" />
                <span><strong>{{ number_format($stats['approved']) }} books</strong><small>{{ number_format($stats['uploadsToday']) }} uploaded today</small></span></a></li>
            <li><a href="{{ route('admin.users.index') }}" class="shortcut"><x-icon-tile name="users" tone="blue" />
                <span><strong>{{ number_format($stats['users']) }} accounts</strong><small>{{ number_format($stats['suspended']) }} suspended</small></span></a></li>
            <li><a href="{{ route('admin.accounts.index') }}" class="shortcut"><x-icon-tile name="clipboard-list" tone="violet" />
                <span><strong>{{ number_format($stats['roll']) }} on the roll</strong><small>{{ number_format($stats['rollWithoutAccount']) }} without an account</small></span></a></li>
        </ul>
    </nav>

    @if ($stats['openElections'])
        <a href="{{ route('elections.manage') }}" class="dashboard-card dashboard-card-vote mt-6 flex items-center gap-3">
            <span class="election-live"><span class="live-dot" aria-hidden="true"></span> Voting open</span>
            <span class="min-w-0 flex-1 text-sm">{{ $stats['openElections'] }} {{ \Illuminate\Support\Str::plural('election', $stats['openElections']) }} running now</span>
            <x-icon name="chevron-right" class="text-muted" />
        </a>
    @endif

    <div class="mt-10 grid gap-8 xl:grid-cols-2">
        <x-section title="Recent activity" description="The latest entries in the audit log." icon="scroll-text" tone="blue">
            <x-slot:action>
                <a href="{{ route('admin.audit') }}" class="section-link">Audit log <x-icon name="arrow-right" /></a>
            </x-slot:action>
            @if ($recentActions->isEmpty())
                <p class="text-sm text-muted">Nothing yet.</p>
            @else
                <ol class="activity-list dashboard-card mt-0">
                    @foreach ($recentActions as $entry)
                        <li>
                            <x-icon name="scroll-text" class="activity-icon" />
                            <span class="min-w-0">
                                <span class="block">{{ \App\Support\AuditActions::label($entry->action) }}@if (isset($entry->meta['title'])) · {{ $entry->meta['title'] }}@elseif (isset($entry->meta['class'])) · {{ $entry->meta['class'] }}@endif</span>
                                <span class="activity-time">{{ $entry->user?->fullname ?? 'System' }} · {{ $entry->created_at->diffForHumans() }}</span>
                            </span>
                        </li>
                    @endforeach
                </ol>
            @endif
        </x-section>

        <x-section title="Latest uploads" description="Newest books, whatever their status." icon="upload" tone="green">
            <x-slot:action>
                <a href="{{ route('admin.books.index') }}" class="section-link">All books <x-icon name="arrow-right" /></a>
            </x-slot:action>
            @if ($recentUploads->isEmpty())
                <p class="text-sm text-muted">No uploads yet.</p>
            @else
                <ul class="upload-list">
                    @foreach ($recentUploads as $book)
                        <li>
                            <a href="{{ route('admin.books.index', ['q' => $book->title]) }}" class="upload-row">
                                <span class="upload-row-cover"><x-book-cover :book="$book" /></span>
                                <span class="min-w-0 flex-1">
                                    <span class="upload-row-title">{{ $book->title }}</span>
                                    <span class="upload-row-meta">{{ $book->uploader?->fullname ?? 'Unknown' }} · {{ $book->created_at->timezone($zone)->format('j M, g:i a') }}</span>
                                </span>
                                <x-upload-status :status="$book->status" class="shrink-0" />
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-section>
    </div>
</x-layouts.admin>
