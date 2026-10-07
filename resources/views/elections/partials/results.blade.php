{{--
    Results from the published snapshot ($results, see LiveResults). With
    $live, elections.js polls the snapshot and updates the numbers in place.
--}}
@php
    $final = (bool) ($results['final'] ?? false);
    $zone = config('app.display_timezone');
    $percent = fn ($value) => \Illuminate\Support\Number::format((float) $value, maxPrecision: 1);
    $live = ($live ?? false) && ! $final;
@endphp

@if ($results === null)
    <p class="text-sm text-muted">Results will appear here once voting opens.</p>
@else
    <div class="results" @if ($live) data-live-results="{{ \App\Support\Elections\LiveResults::url($election) }}" data-poll="{{ config('elections.poll_seconds') }}" data-zone="{{ $zone }}" data-ballots="{{ $results['ballots'] }}" @endif>
        <div class="results-turnout">
            <div class="flex items-end justify-between gap-4">
                <p class="text-sm"><strong class="results-turnout-count" data-result-ballots>{{ number_format($results['ballots']) }}</strong>
                    <span class="text-muted">of <span data-result-electorate>{{ number_format($results['electorate']) }}</span> eligible voted</span></p>
                <p class="results-turnout-percent"><span data-result-turnout>{{ $percent($results['turnout']) }}</span>%</p>
            </div>
            <progress class="result-bar result-bar-lg" max="100" value="{{ $results['turnout'] }}" data-result-turnout-bar aria-label="Turnout {{ $percent($results['turnout']) }}%">{{ $results['turnout'] }}%</progress>
            <p class="results-note">
                @if ($final)
                    <x-icon name="lock" /> Final results
                @else
                    <span class="live-dot" aria-hidden="true"></span>
                    <span>Updated <time data-result-updated datetime="{{ $results['updated_at'] }}">{{ \Illuminate\Support\Carbon::parse($results['updated_at'])->timezone($zone)->format('g:i a') }}</time>. Totals update live as votes come in.</span>
                @endif
            </p>
        </div>

        <ol class="results-positions">
            @foreach ($results['positions'] as $position)
                <li class="results-position">
                    <div class="results-position-head">
                        <h3 class="results-position-title">{{ $position['title'] }}</h3>
                        <p class="text-xs text-muted"><span data-result-position-votes="{{ $position['id'] }}">{{ number_format($position['votes']) }}</span> votes · <span data-result-skipped="{{ $position['id'] }}">{{ number_format($position['skipped']) }}</span> skipped</p>
                    </div>
                    <ul class="results-candidates">
                        @foreach ($position['candidates'] as $candidate)
                            @php
                                $winner = $final && $candidate['leading'] && ! $candidate['tied'];
                                $badge = match (true) {
                                    $winner => 'Winner',
                                    $candidate['tied'] => 'Tied',
                                    $candidate['leading'] => 'Leading',
                                    default => '',
                                };
                            @endphp
                            <li @class(['results-candidate', 'is-leading' => $candidate['leading'], 'is-winner' => $winner]) data-result-candidate="{{ $candidate['id'] }}">
                                <div class="results-candidate-head">
                                    <span class="results-candidate-name">{{ $candidate['name'] }}</span>
                                    <span class="badge {{ $winner ? 'badge-accent' : 'badge-primary' }}" data-result-badge @if ($badge === '') hidden @endif>
                                        @if ($winner)<x-icon name="trophy" />@endif<span data-result-badge-text>{{ $badge }}</span>
                                    </span>
                                    <span class="results-candidate-count"><span data-result-votes>{{ number_format($candidate['votes']) }}</span> · <span data-result-percent>{{ $percent($candidate['percent']) }}</span>%</span>
                                </div>
                                <progress class="result-bar" max="100" value="{{ $candidate['percent'] }}" data-result-bar aria-label="{{ $candidate['name'] }}: {{ $percent($candidate['percent']) }}%">{{ $candidate['percent'] }}%</progress>
                            </li>
                        @endforeach
                    </ul>
                </li>
            @endforeach
        </ol>
    </div>
@endif
