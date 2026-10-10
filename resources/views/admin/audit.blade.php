@php
    $zone = config('app.display_timezone');
    $actionOptions = ['' => 'Every action'] + $actions;
    // Meta keys worth showing, in plain words.
    $metaLabels = ['title' => 'Title', 'name' => 'Name', 'class' => 'Class', 'from' => 'From', 'to' => 'To', 'count' => 'Count', 'total' => 'Total', 'added' => 'Added', 'removed' => 'Removed', 'file' => 'File', 'hours' => 'Hours', 'position' => 'Position', 'candidate' => 'Candidate', 'automatic' => 'Automatic', 'matric' => 'Matric', 'mode' => 'Mode'];
@endphp

<x-layouts.admin title="Audit log">
    <x-page-header title="Audit log" subtitle="Who did what, and when. Votes are recorded as having happened, never how anyone voted.">
        <x-slot:actions>
            <x-button :href="route('admin.audit.export', array_filter($filters))" variant="secondary" size="sm" icon="download">Export CSV</x-button>
        </x-slot:actions>
    </x-page-header>

    <form method="GET" action="{{ route('admin.audit') }}" class="filter-bar mb-6" data-autosubmit>
        <x-select name="action" label="Action" :options="$actionOptions" :value="$filters['action'] ?? ''" />
        <div class="filter-search">
            <label for="audit-who" class="field-label">Who</label>
            <input id="audit-who" name="who" type="search" value="{{ $filters['who'] ?? '' }}" placeholder="Name or matric number" class="field-input">
        </div>
        <x-field name="from" label="From" type="date" :value="$filters['from'] ?? ''" />
        <x-field name="to" label="To" type="date" :value="$filters['to'] ?? ''" />
        <div class="filter-actions"><x-button variant="secondary" icon="search">Filter</x-button></div>
    </form>

    @if ($entries->isEmpty())
        <x-empty-state icon="scroll-text" title="Nothing found" text="No entries match these filters." />
    @else
        <div class="audit-summary">
            <p>Showing <strong>{{ number_format($entries->firstItem()) }}–{{ number_format($entries->lastItem()) }}</strong> of <strong>{{ number_format($entries->total()) }}</strong> {{ \Illuminate\Support\Str::plural('entry', $entries->total()) }}</p>
            @if ($entries->hasPages())
                <p class="audit-summary-pages">
                    Page {{ number_format($entries->currentPage()) }} of {{ number_format($entries->lastPage()) }}
                    @if (! $entries->onFirstPage())
                        <a href="{{ $entries->previousPageUrl() }}" class="icon-btn" aria-label="Newer entries"><x-icon name="chevron-left" /></a>
                    @endif
                    @if ($entries->hasMorePages())
                        <a href="{{ $entries->nextPageUrl() }}" class="icon-btn" aria-label="Older entries"><x-icon name="chevron-right" /></a>
                    @endif
                </p>
            @endif
        </div>

        @php
            $today = now()->timezone($zone)->startOfDay();
            $days = $entries->getCollection()->groupBy(fn ($entry) => $entry->created_at->timezone($zone)->format('Y-m-d'));
        @endphp

        @foreach ($days as $day => $dayEntries)
            @php($date = \Illuminate\Support\Carbon::parse($day, $zone))
            <section class="audit-day" aria-labelledby="audit-day-{{ $day }}">
                <h2 id="audit-day-{{ $day }}" class="audit-day-title">
                    {{ $date->equalTo($today) ? 'Today' : ($date->equalTo($today->copy()->subDay()) ? 'Yesterday' : $date->format('l')) }}
                    <span>{{ $date->format('j M Y') }}</span>
                </h2>
                <ol class="audit-list">
                    @foreach ($dayEntries as $entry)
                        @php($details = collect($entry->meta ?? [])->filter(fn ($value, $key) => isset($metaLabels[$key]) && is_scalar($value)))
                        <li class="audit-entry">
                            <time class="audit-time" datetime="{{ $entry->created_at->toIso8601String() }}">{{ $entry->created_at->timezone($zone)->format('g:i a') }}</time>
                            <span class="audit-dot icon-tile-{{ \App\Support\AuditActions::tone($entry->action) }}" aria-hidden="true"></span>
                            <div class="min-w-0">
                                <p class="audit-line">
                                    <span class="audit-action">{{ \App\Support\AuditActions::label($entry->action) }}</span>
                                    @if ($entry->user)
                                        <span class="text-muted">by</span> <a href="{{ route('admin.users.show', $entry->user) }}" class="link">{{ $entry->user->fullname }}</a>
                                        <span class="audit-matric">{{ $entry->user->matric_number }}</span>
                                    @else
                                        <span class="text-muted">by the system</span>
                                    @endif
                                </p>
                                @if ($details->isNotEmpty() || $entry->ip_address)
                                    <p class="audit-meta">
                                        @foreach ($details as $key => $value)
                                            <span>{{ $metaLabels[$key] }}: {{ is_bool($value) ? ($value ? 'yes' : 'no') : $value }}</span>
                                        @endforeach
                                        @if ($entry->ip_address)
                                            <span>IP {{ $entry->ip_address }}</span>
                                        @endif
                                    </p>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ol>
            </section>
        @endforeach

        {{ $entries->links('partials.pagination') }}
    @endif
</x-layouts.admin>
