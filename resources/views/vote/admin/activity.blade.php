@php
    use App\Support\Departments\ActivityActions;

    $zone = config('app.display_timezone');
    $actionOptions = ['' => 'Every action'] + $actions;
    $electionOptions = ['' => 'Every election'] + $elections;
    $metaLabels = ActivityActions::details();
@endphp

<x-layouts.vote-admin title="Activity">
    <x-page-header title="Activity" subtitle="Who registered, signed in, voted and changed the roll or an election, and when. Votes are recorded as having happened, never how anyone voted.">
        <x-slot:actions>
            <x-button :href="route('vote.admin.activity.export', array_filter($filters))" variant="secondary" size="sm" icon="download">Export CSV</x-button>
        </x-slot:actions>
    </x-page-header>

    <form method="GET" action="{{ route('vote.admin.activity') }}" class="filter-bar mb-6" data-autosubmit>
        <x-select name="action" label="Action" :options="$actionOptions" :value="$filters['action'] ?? ''" />
        @if ($elections !== [])
            <x-select name="election" label="Election" :options="$electionOptions" :value="$filters['election'] ?? ''" />
        @endif
        <div class="filter-search">
            <label for="activity-who" class="field-label">Who</label>
            <input id="activity-who" name="who" type="search" value="{{ $filters['who'] ?? '' }}" placeholder="Name, matric number or email" class="field-input">
        </div>
        <x-field name="from" label="From" type="date" :value="$filters['from'] ?? ''" />
        <x-field name="to" label="To" type="date" :value="$filters['to'] ?? ''" />
        <div class="filter-actions"><x-button variant="secondary" icon="search">Filter</x-button></div>
    </form>

    @if ($entries->isEmpty())
        <x-empty-state icon="scroll-text" title="Nothing found" text="No activity matches these filters." />
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
            <section class="audit-day" aria-labelledby="activity-day-{{ $day }}">
                <h2 id="activity-day-{{ $day }}" class="audit-day-title">
                    {{ $date->equalTo($today) ? 'Today' : ($date->equalTo($today->copy()->subDay()) ? 'Yesterday' : $date->format('l')) }}
                    <span>{{ $date->format('j M Y') }}</span>
                </h2>
                <ol class="audit-list">
                    @foreach ($dayEntries as $entry)
                        @php($details = collect($entry->meta ?? [])->filter(fn ($value, $key) => isset($metaLabels[$key]) && is_scalar($value) && $value !== false))
                        <li class="audit-entry">
                            <time class="audit-time" datetime="{{ $entry->created_at->toIso8601String() }}">{{ $entry->created_at->timezone($zone)->format('g:i a') }}</time>
                            <span class="audit-dot icon-tile-{{ ActivityActions::tone($entry->action) }}" aria-hidden="true"></span>
                            <div class="min-w-0">
                                <p class="audit-line">
                                    <span class="audit-action">{{ ActivityActions::label($entry->action) }}</span>
                                    @if ($entry->actor_name !== null)
                                        <span class="text-muted">by</span> <span class="font-medium">{{ $entry->actor_name }}</span>
                                        <span class="audit-matric">{{ $entry->actor_login }}</span>
                                        @if ($entry->byNacos())
                                            <x-badge variant="primary">NACOS admin</x-badge>
                                        @endif
                                    @elseif ($entry->action === 'login_failed')
                                        <span class="text-muted">with no matching account</span>
                                    @else
                                        <span class="text-muted">by the system</span>
                                    @endif
                                </p>
                                @if ($details->isNotEmpty() || $entry->ip_address)
                                    <p class="audit-meta">
                                        @foreach ($details as $key => $value)
                                            <span>{{ $metaLabels[$key] }}{{ is_bool($value) ? '' : ': '.$value }}</span>
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
</x-layouts.vote-admin>
