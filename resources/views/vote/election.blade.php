@php
    $zone = config('app.display_timezone');
    $accepting = $election->isAcceptingVotes();
    $ended = $election->hasEnded();
    $final = (bool) ($results['final'] ?? false);
    $canVote = $voter && $accepting && ! $hasVoted && $reason === null;
@endphp

<x-layouts.vote :title="$election->title" :description="'Ballot and results: '.$election->title">
    <x-page-header :title="$election->title" :subtitle="$election->description" :back="route('vote.home')" :back-label="$organization->shortLabel().' elections'" />

    <div class="election-meta animate-enter">
        @if ($election->isDraft())
            <x-badge variant="accent">Draft: only admins see this</x-badge>
        @elseif ($final)
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
                    @include('elections.partials.ballot', ['action' => route('vote.elections.vote', $election)])
                @elseif ($hasVoted)
                    <div @class(['vote-done', 'animate-enter' => session('voted')])>
                        <x-icon-tile name="circle-check" tone="green" size="lg" />
                        <div>
                            <h2 class="text-xl">{{ session('voted') ? 'Your vote is in' : 'You have voted' }}</h2>
                            <p class="mt-1 text-muted">Thank you for voting. Your ballot is secret: we keep a record that you voted, never who you chose. Follow the results as they come in.</p>
                        </div>
                    </div>
                @elseif ($election->isDraft())
                    <x-empty-state icon="info" title="Not open yet" text="Voting hasn't opened. Students can't see this election until it does." />
                @elseif ($ended)
                    <x-empty-state icon="timer" tone="yellow" title="Voting has ended" text="The final results will be published in a moment." />
                @elseif (! $voter)
                    <x-empty-state icon="vote" tone="green" title="Log in to vote" text="Log in with the account you registered here. Not registered? Only accounts made before voting opened can usually vote. Anyone can follow the results.">
                        <x-button href="{{ route('vote.login') }}" icon="log-in">Log in</x-button>
                    </x-empty-state>
                @elseif ($reason)
                    <x-empty-state icon="info" title="You can't vote in this election" :text="$reason" />
                @endif
            </div>
        @endunless

        @if ($results)
            <section aria-labelledby="results-heading" @class(['min-w-0', 'lg:sticky lg:top-24' => ! $final && count($results['positions'] ?? []) <= 2])>
                <div class="mb-4 flex items-center gap-3">
                    <x-icon-tile :name="$final ? 'trophy' : 'chart-column'" :tone="$final ? 'yellow' : 'blue'" size="sm" />
                    <h2 id="results-heading" class="text-lg">{{ $final ? 'Final results' : 'Live results' }}</h2>
                </div>
                @include('elections.partials.results', ['results' => $results, 'election' => $election, 'live' => ! $final])
            </section>
        @endif
    </div>
</x-layouts.vote>
