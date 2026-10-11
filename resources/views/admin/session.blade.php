@php
    $zone = config('app.display_timezone');
@endphp

<x-layouts.admin title="New session">
    <x-page-header title="New session" subtitle="Full-time, part-time and CODFEL keep separate calendars, so each starts its new session on its own." />

    <nav class="review-tabs mb-6" aria-label="Programme">
        @foreach ($tabs as $tab)
            <a href="{{ route('admin.session', ['programme' => $tab['programme']->value]) }}" class="review-tab" @if ($tab['programme'] === $programme) aria-current="page" @endif>
                {{ $tab['programme']->label() }} <span class="review-tab-count">{{ $tab['session'] ?? 'not set' }}</span>
            </a>
        @endforeach
    </nav>

    @error('undo')
        <x-alert type="error" class="mb-6">{{ $message }}</x-alert>
    @enderror

    @if ($undoable)
        @php($done = $undoable->summary ?? [])
        <x-card class="mb-8 space-y-4" id="undo">
            <div class="flex items-start gap-3">
                <x-icon-tile name="circle-check" tone="green" size="sm" />
                <div class="min-w-0">
                    <h2 class="text-base">{{ $programme->label() }} {{ $undoable->to_session }} has started</h2>
                    <p class="text-sm text-muted">
                        {{ $undoable->started_at->timezone($zone)->format('j M Y, g:i a') }}@if ($undoable->starter) by {{ $undoable->starter->fullname }}@endif:
                        {{ number_format($done['promoted'] ?? 0) }} moved up, {{ number_format($done['graduated'] ?? 0) }} graduated,
                        {{ number_format($done['roll'] ?? 0) }} roll entries cleared, {{ number_format(($done['lectures'] ?? 0) + ($done['exams'] ?? 0)) }} timetable entries archived.
                    </p>
                </div>
            </div>
            <form method="POST" action="{{ route('admin.session.undo', $undoable) }}" class="flex flex-wrap items-center gap-3"
                data-confirm="Undo the start of {{ $programme->label() }} {{ $undoable->to_session }}? Everyone goes back to their class, the roll and timetables come back, and the session is {{ $undoable->from_session ?? 'unset' }} again.">
                @csrf
                <x-button variant="secondary" icon="rotate-ccw">Undo</x-button>
                <p class="text-sm text-muted">Possible until the first new {{ $programme->label() }} roll is uploaded.</p>
            </form>
        </x-card>
    @endif

    <div class="grid grid-cols-1 gap-8 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start">
        <div class="min-w-0 space-y-8">
            <x-section :title="'What starting '.$programme->label().' '.$next.' does'" :description="'Current session: '.($current ?? 'not set').'. Moves follow the programme\'s length ('.collect(config('classes.years.'.$programme->value))->map(fn ($years, $stage) => $stage.' '.$years.' years')->implode(', ').'). Other programmes are untouched.'" icon="graduation-cap" tone="green">
                <ul class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                    <li class="stat-tile"><strong>{{ number_format($preview['promoted']) }}</strong><small>Move up a level</small></li>
                    <li class="stat-tile"><strong>{{ number_format($preview['graduated']) }}</strong><small>Graduate</small></li>
                    <li class="stat-tile"><strong>{{ number_format($preview['roll']) }}</strong><small>Roll entries cleared</small></li>
                    <li class="stat-tile"><strong>{{ number_format($preview['lectures']) }}</strong><small>Lectures archived</small></li>
                    <li class="stat-tile"><strong>{{ number_format($preview['exams']) }}</strong><small>Exam papers archived</small></li>
                </ul>
            </x-section>

            <x-section title="Class by class" :description="$programme->label().' students with an account, by the class on their profile.'">
                @if ($preview['classes'] === [])
                    <x-empty-state icon="users" title="No current students" :text="'There\'s nobody in '.$programme->label().' to move up.'" />
                @else
                    <div class="table-scroll" tabindex="0" role="region" aria-label="Classes, scrolls sideways">
                        <table class="admin-table">
                            <thead>
                                <tr><th>Now</th><th>Next session</th><th class="num">Students</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($preview['classes'] as $class)
                                    <tr>
                                        <td class="font-semibold">{{ $class['from'] }}</td>
                                        <td>
                                            @if ($class['to'])
                                                <span class="inline-flex items-center gap-1.5"><x-icon name="arrow-right" class="size-4 text-muted" /> {{ $class['to'] }}</span>
                                            @else
                                                <x-badge variant="accent"><x-icon name="graduation-cap" /> Graduate</x-badge>
                                            @endif
                                        </td>
                                        <td class="num">{{ number_format($class['count']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-section>

            <x-section title="Good to know" icon="info" tone="blue">
                <ul class="list-disc space-y-1.5 pl-5 text-sm text-muted">
                    <li>Graduates keep their account and can read the library, but can't vote or upload. Graduating course reps and governors become students.</li>
                    <li>The {{ $programme->label() }} nominal roll starts empty: upload each class's new list. New ND1 and HND1 students sign up as usual.</li>
                    <li>{{ $programme->label() }} class and exam timetables are archived, and its exam timetable is hidden until you show it.</li>
                    <li>Other programmes keep their session, roll and timetables.</li>
                    <li>Accounts, books, uploads, announcements and past elections stay as they are.</li>
                </ul>
            </x-section>

            @if ($history->isNotEmpty())
                <x-section :title="'Earlier '.$programme->label().' new sessions'">
                    <ul class="space-y-2 text-sm">
                        @foreach ($history as $change)
                            <li class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border bg-surface px-4 py-3">
                                <span><strong>{{ $change->from_session ?? '—' }} → {{ $change->to_session }}</strong> <span class="text-muted">· {{ $change->started_at->timezone($zone)->format('j M Y') }}@if ($change->starter) · {{ $change->starter->fullname }}@endif</span></span>
                                @if ($change->undone_at)
                                    <x-badge>Undone</x-badge>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </x-section>
            @endif
        </div>

        <aside>
            <x-card class="space-y-4" id="start">
                <div class="flex items-center gap-3">
                    <x-icon-tile name="graduation-cap" tone="yellow" size="sm" />
                    <h2 class="text-base">Start {{ $programme->label() }} {{ $next }}</h2>
                </div>
                @if ($blocker)
                    <x-alert type="warning">{{ $blocker }}</x-alert>
                @endif
                <form method="POST" action="{{ route('admin.session.start') }}" class="space-y-4" novalidate
                    data-confirm="Start {{ $programme->label() }} {{ $next }} now? Its students move up and graduate, its roll is cleared and its timetables are archived.">
                    @csrf
                    <input type="hidden" name="programme" value="{{ $programme->value }}">
                    <fieldset class="space-y-4" @disabled($blocker)>
                        <x-field name="to_session" label="New session" :value="$next" required pattern="20[0-9]{2}/20[0-9]{2}" hint="The programme's new session." />
                        <x-field name="confirm" label="Type the new session to confirm" required autocomplete="off" :placeholder="$next" />
                        <x-button icon="graduation-cap" class="w-full">Start the new session</x-button>
                    </fieldset>
                </form>
                <p class="text-xs text-muted">You can undo it until the first new {{ $programme->label() }} roll is uploaded.</p>
            </x-card>
        </aside>
    </div>
</x-layouts.admin>
