@php
    $zone = config('app.display_timezone');
    $percent = fn ($value) => \Illuminate\Support\Number::format((float) $value, maxPrecision: 1);
@endphp

<x-layouts.app title="Elections" description="NACOS YabaTech elections: vote, and follow the results live.">
    <x-page-header title="Elections" subtitle="Vote for your NACOS leaders. Results are public and update while voting is open.">
        <x-slot:actions>
            @can('manage-elections')
                <x-button href="{{ route('elections.manage') }}" variant="secondary" icon="settings">Manage elections</x-button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    @if ($open->isEmpty())
        <x-empty-state icon="vote" tone="green" title="No election active" text="When voting opens, the election will appear here with its ballot and live results." />
    @else
        <ul class="election-grid stagger">
            @foreach ($open as $election)
                @php
                    $result = $results[$election->id] ?? null;
                    $hasVoted = isset($voted[$election->id]);
                    $reason = $user ? $election->ineligibilityReason($user) : null;
                    $ended = $election->hasEnded();
                @endphp
                <li>
                    <article class="election-card">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            @if ($ended)
                                <x-badge>Counting</x-badge>
                            @else
                                <span class="election-live"><span class="live-dot" aria-hidden="true"></span> Voting open</span>
                            @endif
                            @if ($election->ends_at)
                                @include('elections.partials.countdown', ['ends' => $election->ends_at])
                            @endif
                        </div>

                        <h2 class="election-card-title"><a href="{{ route('elections.show', $election) }}" class="stretched-link">{{ $election->title }}</a></h2>
                        @if ($election->description)
                            <p class="election-card-text">{{ $election->description }}</p>
                        @endif

                        <dl class="election-facts">
                            <div><dt><x-icon name="users" /> Who can vote</dt><dd>{{ $election->eligibilitySummary() }}</dd></div>
                            <div><dt><x-icon name="calendar-clock" /> Closes</dt><dd>{{ $election->ends_at?->timezone($zone)->format('D j M, g:i a') ?? 'When stopped' }}</dd></div>
                        </dl>

                        @if ($result)
                            <div>
                                <div class="flex items-baseline justify-between gap-3 text-sm">
                                    <span><strong>{{ number_format($result['ballots']) }}</strong> <span class="text-muted">of {{ number_format($result['electorate']) }} voted</span></span>
                                    <span class="font-semibold">{{ $percent($result['turnout']) }}%</span>
                                </div>
                                <progress class="result-bar result-bar-strong" max="100" value="{{ $result['turnout'] }}" aria-label="Turnout {{ $percent($result['turnout']) }}%">{{ $result['turnout'] }}%</progress>
                            </div>
                        @endif

                        <div class="election-card-foot">
                            @if ($hasVoted)
                                <span class="election-voted"><x-icon name="circle-check" /> You voted</span>
                                <span class="section-link">See results <x-icon name="arrow-right" /></span>
                            @elseif ($ended)
                                <span class="text-sm text-muted">Voting has ended.</span>
                                <span class="section-link">See results <x-icon name="arrow-right" /></span>
                            @elseif (! $user)
                                <span class="btn btn-primary btn-sm">Log in to vote</span>
                                <span class="section-link">Live results <x-icon name="arrow-right" /></span>
                            @elseif ($reason)
                                <span class="text-sm text-muted">{{ $reason }}</span>
                                <span class="section-link">Live results <x-icon name="arrow-right" /></span>
                            @else
                                <span class="btn btn-primary btn-sm"><x-icon name="vote" /> Vote now</span>
                                <span class="section-link">Live results <x-icon name="arrow-right" /></span>
                            @endif
                        </div>
                    </article>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($past->isNotEmpty())
        <x-section title="Past elections" description="Final results of earlier elections." icon="trophy" tone="yellow" class="mt-12">
            <ul class="upload-list">
                @foreach ($past as $election)
                    @php($result = $results[$election->id] ?? null)
                    <li>
                        <a href="{{ route('elections.show', $election) }}" class="upload-row">
                            <x-icon-tile name="vote" tone="neutral" size="sm" />
                            <span class="min-w-0 flex-1">
                                <span class="upload-row-title">{{ $election->title }}</span>
                                <span class="upload-row-meta">
                                    Closed {{ ($election->closed_at ?? $election->ends_at)?->timezone($zone)->format('j M Y') }}
                                    @if ($result) · {{ number_format($result['ballots']) }} voted ({{ $percent($result['turnout']) }}%) @endif
                                </span>
                            </span>
                            <x-badge class="shrink-0">Final</x-badge>
                            <x-icon name="chevron-right" class="shrink-0 text-muted" />
                        </a>
                    </li>
                @endforeach
            </ul>
        </x-section>
    @endif
</x-layouts.app>
