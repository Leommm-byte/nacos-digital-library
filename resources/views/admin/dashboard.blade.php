@php($zone = config('app.display_timezone'))

<x-layouts.admin title="Dashboard">
    <x-page-header title="Admin" :subtitle="$session ? 'Sessions: '.$session : 'Run NACOS YabaTech: accounts, the library, elections and settings.'" />

    <nav aria-label="At a glance">
        <ul class="stagger grid grid-cols-2 gap-3 xl:grid-cols-4">
            <li><a href="{{ route('review.index') }}" class="shortcut"><x-icon-tile name="shield-check" tone="yellow" />
                <span><strong>{{ number_format($stats['pending']) }} waiting</strong><small>Uploads to review</small></span></a></li>
            <li><a href="{{ route('admin.books.index') }}" class="shortcut"><x-icon-tile name="library-big" tone="green" />
                <span><strong>{{ number_format($stats['approved']) }} {{ \Illuminate\Support\Str::plural('book', $stats['approved']) }}</strong><small>{{ number_format($stats['uploadsToday']) }} uploaded today</small></span></a></li>
            <li><a href="{{ route('admin.users.index') }}" class="shortcut"><x-icon-tile name="users" tone="blue" />
                <span><strong>{{ number_format($stats['users']) }} accounts</strong><small>{{ number_format($stats['suspended']) }} suspended</small></span></a></li>
            <li><a href="{{ route('admin.accounts.index') }}" class="shortcut"><x-icon-tile name="clipboard-list" tone="violet" />
                <span><strong>{{ number_format($stats['roll']) }} on the roll</strong><small>{{ number_format($stats['rollWithoutAccount']) }} need an account</small></span></a></li>
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

    <x-section class="mt-10" title="Site health" description="The cron, backups and the version that's live. Set up in docs/deployment.md." icon="activity" tone="violet">
        <dl class="dashboard-card health-list mt-0">
            <div>
                <dt>Cron</dt>
                <dd>
                    <x-badge :variant="$health['cronOk'] ? 'primary' : 'danger'">{{ $health['cronOk'] ? 'Running' : 'Not running' }}</x-badge>
                    <span class="text-sm text-muted">{{ $health['cron'] ? 'Last ran '.$health['cron']->diffForHumans() : 'Has never run' }}</span>
                </dd>
            </div>
            <div>
                <dt>Database backup</dt>
                <dd>
                    <x-badge :variant="$health['backupOk'] && $health['offsite'] ? 'primary' : 'danger'">{{ ! $health['backupOk'] ? 'Overdue' : ($health['offsite'] ? 'Up to date' : 'Server only') }}</x-badge>
                    <span class="text-sm text-muted">{{ $health['backup'] ? 'Last '.$health['backup']->timezone($zone)->format('j M, g:i a') : 'None yet' }}{{ $health['offsite'] ? '' : ' · no off-site storage set up' }}</span>
                </dd>
            </div>
            <div>
                <dt>Uploaded files</dt>
                <dd>
                    <span class="text-sm text-muted">
                        @if (! $health['offsite'])
                            Not copied off-site (no off-site storage set up)
                        @elseif ($health['files'])
                            Copied off-site {{ $health['files']->diffForHumans() }}@if ($health['filesWaiting']) · {{ number_format($health['filesWaiting']) }} waiting for the next run @endif
                        @else
                            Not copied yet
                        @endif
                    </span>
                </dd>
            </div>
            @if ($health['failure'])
                <div>
                    <dt>Last backup error</dt>
                    <dd class="text-sm text-danger">{{ $health['failure'] }}</dd>
                </div>
            @endif
            <div>
                <dt>Version</dt>
                <dd class="text-sm text-muted">{{ $health['release'] === 'unknown' ? 'Not deployed by the Deploy workflow' : $health['release'] }}</dd>
            </div>
        </dl>
    </x-section>
</x-layouts.admin>
