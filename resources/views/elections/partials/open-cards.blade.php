{{-- Open elections as cards: turnout, eligibility and the voter's next step.
     Expects $open, $results, $voted, $user (or null) and $show (election => URL). --}}
@php
    $zone = config('app.display_timezone');
    $percent = fn ($value) => \Illuminate\Support\Number::format((float) $value, maxPrecision: 1);
@endphp
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

                <h2 class="election-card-title"><a href="{{ $show($election) }}" class="stretched-link">{{ $election->title }}</a></h2>
                @if ($election->description)
                    <p class="election-card-text">{{ $election->description }}</p>
                @endif

                <dl class="election-facts">
                    <div><dt><x-icon name="users" /> Who can vote</dt><dd>{{ $election->eligibilitySummary() }}</dd></div>
                    <div><dt><x-icon name="calendar-clock" /> Closes</dt><dd>{{ $election->ends_at?->timezone($zone)->format('D j M, g:i a') ?? 'When stopped' }}</dd></div>
                </dl>

                @if ($result)
                    {{-- Turnout updates itself while voting is open (live-results.js). --}}
                    <div @if (! ($result['final'] ?? false)) data-live-results="{{ \App\Support\Elections\LiveResults::url($election) }}" data-poll="{{ config('elections.poll_seconds') }}" data-poll-max="{{ config('elections.poll_max_seconds') }}" data-zone="{{ $zone }}" data-ballots="{{ $result['ballots'] }}" @endif>
                        <div class="flex items-baseline justify-between gap-3 text-sm">
                            <span><strong data-result-ballots>{{ number_format($result['ballots']) }}</strong> <span class="text-muted">of <span data-result-electorate>{{ number_format($result['electorate']) }}</span> voted</span></span>
                            <span class="font-semibold"><span data-result-turnout>{{ $percent($result['turnout']) }}</span>%</span>
                        </div>
                        <progress class="result-bar result-bar-strong" max="100" value="{{ $result['turnout'] }}" data-result-turnout-bar aria-label="Turnout {{ $percent($result['turnout']) }}%">{{ $result['turnout'] }}%</progress>
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
