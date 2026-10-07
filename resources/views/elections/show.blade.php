@php
    $zone = config('app.display_timezone');
    $accepting = $election->isAcceptingVotes();
    $ended = $election->hasEnded();
    $final = (bool) ($results['final'] ?? false);
    $canVote = $user && $accepting && ! $hasVoted && $reason === null;
@endphp

<x-layouts.app :title="$election->title" :description="'Ballot and results: '.$election->title">
    <x-page-header :title="$election->title" :subtitle="$election->description" :back="route('elections.index')" back-label="Elections" />

    <div class="election-meta animate-enter">
        @if ($final)
            <x-badge><x-icon name="lock" /> Closed</x-badge>
        @elseif ($ended)
            <x-badge variant="accent">Counting</x-badge>
        @else
            <span class="election-live"><span class="live-dot" aria-hidden="true"></span> Voting open</span>
        @endif
        @if ($election->ends_at && ! $final)
            @include('elections.partials.countdown', ['ends' => $election->ends_at, 'reload' => true])
        @endif
        <span class="election-meta-item"><x-icon name="users" /> {{ $election->eligibilitySummary() }}</span>
        @if ($election->ends_at)
            <span class="election-meta-item"><x-icon name="calendar-clock" /> {{ $final ? 'Closed' : 'Closes' }} {{ ($election->closed_at ?? $election->ends_at)->timezone($zone)->format('D j M, g:i a') }}</span>
        @endif
    </div>

    <div @class(['mt-8 grid gap-8', 'lg:grid-cols-[minmax(0,1fr)_24rem] lg:items-start' => ! $final])>
        @unless ($final)
            <div class="min-w-0">
                @if ($canVote)
                    <form method="POST" action="{{ route('elections.vote', $election) }}" class="space-y-5" data-ballot
                        data-confirm="Cast your vote? You can't change it afterwards.">
                        @csrf
                        @error('ballot')
                            <x-alert type="error">{{ $message }}</x-alert>
                        @enderror

                        <div class="ballot-intro">
                            <x-icon-tile name="vote" tone="green" size="sm" />
                            <p class="text-sm text-muted">Choose one candidate for each position, or leave a position out. Your ballot is secret: we keep a record that you voted, never who you chose.</p>
                        </div>

                        @foreach ($election->positions as $position)
                            @php($chosen = (string) old("choices.{$position->id}", ''))
                            <fieldset class="ballot-position">
                                <legend class="ballot-position-title"><span class="upload-step"><span>{{ $loop->iteration }}</span></span> {{ $position->title }}</legend>
                                <div class="ballot-options">
                                    @foreach ($position->candidates as $candidate)
                                        <label class="ballot-option">
                                            <input type="radio" name="choices[{{ $position->id }}]" value="{{ $candidate->id }}" class="sr-only" @checked($chosen === (string) $candidate->id) data-ballot-choice>
                                            <x-candidate-avatar :name="$candidate->name" />
                                            <span class="min-w-0 flex-1">
                                                <span class="ballot-option-name">{{ $candidate->name }}</span>
                                                @if ($candidate->manifesto)
                                                    <span class="ballot-option-text">{{ $candidate->manifesto }}</span>
                                                @endif
                                            </span>
                                            <span class="ballot-option-check" aria-hidden="true"><x-icon name="circle-check" /></span>
                                        </label>
                                    @endforeach
                                    <label class="ballot-option ballot-option-skip">
                                        <input type="radio" name="choices[{{ $position->id }}]" value="" class="sr-only" @checked($chosen === '')>
                                        <span class="ballot-option-name">No vote for {{ $position->title }}</span>
                                    </label>
                                </div>
                            </fieldset>
                        @endforeach

                        <div class="ballot-submit">
                            <p class="text-sm" data-ballot-summary aria-live="polite">{{ $election->positions->count() }} {{ \Illuminate\Support\Str::plural('position', $election->positions->count()) }} on this ballot.</p>
                            <x-button icon="vote">Cast my vote</x-button>
                        </div>
                    </form>
                @elseif ($hasVoted)
                    <div @class(['vote-done', 'animate-enter' => session('voted')])>
                        <x-icon-tile name="circle-check" tone="green" size="lg" />
                        <div>
                            <h2 class="text-xl">{{ session('voted') ? 'Your vote is in' : 'You have voted' }}</h2>
                            <p class="mt-1 text-muted">Thank you for voting. Your ballot is secret: we keep a record that you voted, never who you chose. Follow the results as they come in.</p>
                        </div>
                    </div>
                @elseif ($ended)
                    <x-empty-state icon="timer" tone="yellow" title="Voting has ended" text="The final results will be published in a moment." />
                @elseif (! $user)
                    <x-empty-state icon="vote" tone="green" title="Log in to vote" text="You need your NACOS account to vote. Anyone can follow the results.">
                        <x-button href="{{ route('login') }}" icon="log-in">Log in</x-button>
                    </x-empty-state>
                @elseif ($reason)
                    <x-empty-state icon="info" title="You can't vote in this election" :text="$reason" />
                @endif
            </div>
        @endunless

        <section aria-labelledby="results-heading" @class(['card p-5 sm:p-6', 'lg:sticky lg:top-24' => ! $final])>
            <div class="mb-4 flex items-center gap-3">
                <x-icon-tile :name="$final ? 'trophy' : 'chart-column'" :tone="$final ? 'yellow' : 'blue'" size="sm" />
                <h2 id="results-heading" class="text-lg">{{ $final ? 'Final results' : 'Live results' }}</h2>
            </div>
            @include('elections.partials.results', ['results' => $results, 'election' => $election, 'live' => ! $final])
        </section>
    </div>
</x-layouts.app>
