@php
    $zone = config('app.display_timezone');
    $draft = $election->isDraft();
    $open = $election->status === \App\Enums\ElectionStatus::Open;
    $final = (bool) ($results['final'] ?? false);
    $durations = [];
    foreach (\App\Models\Election::DURATIONS as $hours) {
        $durations[$hours] = $hours < 24
            ? $hours.' '.\Illuminate\Support\Str::plural('hour', $hours)
            : intdiv($hours, 24).' '.\Illuminate\Support\Str::plural('day', intdiv($hours, 24));
    }
@endphp

<x-layouts.app :title="$election->title">
    <x-page-header :title="$election->title" :subtitle="$election->description" eyebrow="Manage election" :back="route('elections.manage')" back-label="Manage elections">
        <x-slot:actions>
            @if ($draft)
                <x-button href="{{ route('elections.manage.edit', $election) }}" variant="secondary" icon="pencil">Edit details</x-button>
            @else
                <x-button href="{{ route('elections.show', $election) }}" variant="secondary" icon="eye">Public page</x-button>
            @endif
        </x-slot:actions>
    </x-page-header>

    @error('launch')
        <x-alert type="error" class="mb-6">{{ $message }}</x-alert>
    @enderror

    <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start">
        <div class="min-w-0 space-y-6">
            @if ($draft)
                <x-section title="Ballot" description="Positions appear on the ballot in this order. Voters choose one candidate per position." icon="list-checks" tone="green">
                    @if ($election->positions->isEmpty())
                        <x-empty-state icon="list-checks" title="No positions yet" text="Add the first position below, for example President." />
                    @endif

                    <ol class="space-y-4">
                        @foreach ($election->positions as $position)
                            @php
                                $addBag = $errors->getBag('candidate-'.$position->id);
                                $renameBag = $errors->getBag('position-'.$position->id);
                            @endphp
                            <li id="position-{{ $position->id }}" class="setup-position">
                                <div class="setup-position-head">
                                    <span class="upload-step"><span>{{ $loop->iteration }}</span></span>
                                    <h3 class="min-w-0 flex-1 text-base">{{ $position->title }}</h3>
                                    <div class="flex items-center gap-1">
                                        <form method="POST" action="{{ route('elections.manage.positions.move', [$election, $position]) }}">
                                            @csrf
                                            <input type="hidden" name="direction" value="up">
                                            <button type="submit" class="icon-btn" aria-label="Move {{ $position->title }} up" @disabled($loop->first)><x-icon name="arrow-up" /></button>
                                        </form>
                                        <form method="POST" action="{{ route('elections.manage.positions.move', [$election, $position]) }}">
                                            @csrf
                                            <input type="hidden" name="direction" value="down">
                                            <button type="submit" class="icon-btn" aria-label="Move {{ $position->title }} down" @disabled($loop->last)><x-icon name="arrow-down" /></button>
                                        </form>
                                        <form method="POST" action="{{ route('elections.manage.positions.destroy', [$election, $position]) }}" data-confirm="Remove {{ $position->title }} and its candidates?">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="icon-btn icon-btn-danger" aria-label="Remove {{ $position->title }}"><x-icon name="trash-2" /></button>
                                        </form>
                                    </div>
                                </div>

                                @if ($position->candidates->isEmpty())
                                    <p class="setup-empty">No candidates yet.</p>
                                @else
                                    <ul class="setup-candidates">
                                        @foreach ($position->candidates as $candidate)
                                            @php($editBag = $errors->getBag('candidate-edit-'.$candidate->id))
                                            <li>
                                                <div class="setup-candidate">
                                                    <x-candidate-avatar :name="$candidate->name" size="sm" />
                                                    <div class="min-w-0 flex-1">
                                                        <p class="font-semibold">{{ $candidate->name }}</p>
                                                        <p class="text-xs text-muted">{{ $candidate->matric_number ?? 'No matric number' }}@if ($candidate->user_id) · has an account @endif</p>
                                                        @if ($candidate->manifesto)
                                                            <p class="mt-1 line-clamp-2 text-sm text-muted">{{ $candidate->manifesto }}</p>
                                                        @endif
                                                    </div>
                                                    <form method="POST" action="{{ route('elections.manage.candidates.destroy', [$election, $position, $candidate]) }}" data-confirm="Remove {{ $candidate->name }}?">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="icon-btn icon-btn-danger" aria-label="Remove {{ $candidate->name }}"><x-icon name="trash-2" /></button>
                                                    </form>
                                                </div>
                                                <details class="setup-details" @if ($editBag->any()) open @endif>
                                                    <summary>Edit {{ $candidate->name }}</summary>
                                                    @include('elections.manage.candidate-form', [
                                                        'action' => route('elections.manage.candidates.update', [$election, $position, $candidate]),
                                                        'method' => 'PUT',
                                                        'bag' => 'candidate-edit-'.$candidate->id,
                                                        'prefix' => 'candidate-'.$candidate->id,
                                                        'candidate' => $candidate,
                                                        'submit' => 'Save candidate',
                                                    ])
                                                </details>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif

                                <details class="setup-details" @if ($addBag->any() || $position->candidates->isEmpty()) open @endif>
                                    <summary><x-icon name="user-plus" /> Add a candidate</summary>
                                    @include('elections.manage.candidate-form', [
                                        'action' => route('elections.manage.candidates.store', [$election, $position]),
                                        'method' => 'POST',
                                        'bag' => 'candidate-'.$position->id,
                                        'prefix' => 'new-candidate-'.$position->id,
                                        'candidate' => null,
                                        'submit' => 'Add candidate',
                                    ])
                                </details>

                                <details class="setup-details" @if ($renameBag->any()) open @endif>
                                    <summary><x-icon name="pencil" /> Rename position</summary>
                                    <form method="POST" action="{{ route('elections.manage.positions.update', [$election, $position]) }}" class="setup-form" novalidate>
                                        @csrf
                                        @method('PUT')
                                        <x-field name="title" :id="'position-title-'.$position->id" label="Position" :bag="'position-'.$position->id" required maxlength="120" :value="$position->title" :old="$renameBag->any()" />
                                        <x-button size="sm" variant="secondary">Rename</x-button>
                                    </form>
                                </details>
                            </li>
                        @endforeach
                    </ol>

                    <form method="POST" action="{{ route('elections.manage.positions.store', $election) }}" class="setup-add mt-4" novalidate>
                        @csrf
                        <x-field name="title" id="new-position" label="Add a position" bag="position" required maxlength="120" placeholder="e.g. Welfare Director" :old="$errors->getBag('position')->any()" />
                        <x-button icon="plus" variant="secondary">Add position</x-button>
                    </form>
                </x-section>
            @else
                <section aria-labelledby="results-heading" class="card p-5 sm:p-6">
                    <div class="mb-4 flex items-center gap-3">
                        <x-icon-tile :name="$final ? 'trophy' : 'chart-column'" :tone="$final ? 'yellow' : 'blue'" size="sm" />
                        <h2 id="results-heading" class="text-lg">{{ $final ? 'Final results' : 'Live standings' }}</h2>
                    </div>
                    @include('elections.partials.results', ['results' => $results, 'election' => $election, 'live' => ! $final])
                </section>
            @endif
        </div>

        <aside class="space-y-4 lg:sticky lg:top-24">
            <x-card class="space-y-4">
                <div class="flex items-center gap-3">
                    <x-icon-tile name="users" tone="blue" size="sm" />
                    <h2 class="text-base">Who can vote</h2>
                </div>
                <p class="text-sm">{{ $election->eligibilitySummary() }}.</p>
                <p class="text-sm text-muted">{{ number_format($electorate) }} active {{ \Illuminate\Support\Str::plural('account', $electorate) }} can vote{{ $draft ? ' right now' : '' }}.@if ($draft) <a href="{{ route('elections.manage.edit', $election) }}" class="link">Change</a>@endif</p>
            </x-card>

            @if ($draft)
                <x-card class="space-y-4">
                    <div class="flex items-center gap-3">
                        <x-icon-tile name="rocket" tone="green" size="sm" />
                        <h2 class="text-base">Launch</h2>
                    </div>
                    <p class="text-sm text-muted">Voting opens straight away and closes on its own when the time is up. After launch, positions and candidates can't change.</p>
                    @if ($launchProblem)
                        <x-alert type="warning">{{ $launchProblem }}</x-alert>
                    @endif
                    <form method="POST" action="{{ route('elections.manage.launch', $election) }}" class="space-y-4"
                        data-confirm="Open voting now? Positions and candidates can't change after this.">
                        @csrf
                        <x-select name="hours" label="Voting time" :value="6" :options="$durations" />
                        <x-button icon="rocket" class="w-full" :disabled="$launchProblem !== null">Open voting</x-button>
                    </form>
                </x-card>

                <form method="POST" action="{{ route('elections.manage.destroy', $election) }}" class="px-1" data-confirm="Delete this draft election?">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="link text-sm text-danger">Delete this draft</button>
                </form>
            @elseif ($open)
                <x-card class="space-y-4">
                    <div class="flex items-center justify-between gap-3">
                        <span class="election-live"><span class="live-dot" aria-hidden="true"></span> Voting open</span>
                        @if ($election->ends_at)
                            @include('elections.partials.countdown', ['ends' => $election->ends_at, 'reload' => true])
                        @endif
                    </div>
                    <dl class="profile-summary mt-0">
                        <div><dt>Opened</dt><dd>{{ $election->starts_at?->timezone($zone)->format('D j M, g:i a') }}</dd></div>
                        <div><dt>Closes</dt><dd>{{ $election->ends_at?->timezone($zone)->format('D j M, g:i a') }}</dd></div>
                        <div><dt>Ballots cast</dt><dd>{{ number_format($ballots) }}</dd></div>
                    </dl>
                    <form method="POST" action="{{ route('elections.manage.close', $election) }}" data-confirm="Stop voting now? This can't be undone, and the final results will be published.">
                        @csrf
                        <x-button variant="danger" icon="circle-stop" class="w-full">Stop voting now</x-button>
                    </form>
                </x-card>
            @else
                <x-card class="space-y-3">
                    <div class="flex items-center gap-3">
                        <x-icon-tile name="lock" tone="neutral" size="sm" />
                        <h2 class="text-base">Closed</h2>
                    </div>
                    <dl class="profile-summary mt-0">
                        <div><dt>Voting</dt><dd>{{ $election->starts_at?->timezone($zone)->format('j M, g:i a') }} to {{ ($election->closed_at ?? $election->ends_at)?->timezone($zone)->format('j M, g:i a') }}</dd></div>
                        <div><dt>Ballots cast</dt><dd>{{ number_format($ballots) }}</dd></div>
                    </dl>
                    <p class="text-sm text-muted">A closed election can't be reopened. Create a new one for a re-run.</p>
                </x-card>
            @endif
        </aside>
    </div>
</x-layouts.app>
