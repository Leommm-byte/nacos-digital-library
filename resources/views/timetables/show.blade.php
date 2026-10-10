@php
    $canEdit = $class && Gate::allows('edit-timetable', $class);
@endphp

<x-layouts.app title="Timetable">
    <x-page-header title="Timetable" :subtitle="$class ? $class->label().' · this week' : null">
        <x-slot:actions>
            <x-button href="{{ route('exams.index') }}" variant="secondary" icon="calendar-days">Exam timetable</x-button>
            @if ($canEdit)
                <x-button href="{{ route('timetable.edit', $classes ? ['class' => $class->key()] : []) }}" icon="pencil">Edit timetable</x-button>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($classes)
        <form method="GET" action="{{ route('timetable.show') }}" class="mb-6 max-w-sm" data-autosubmit>
            <x-select name="class" id="timetable-class" label="Class" :options="$classes" :value="$class?->key()" :old="false" />
            <noscript><x-button variant="secondary" size="sm" class="mt-2">Show</x-button></noscript>
        </form>
    @endif

    @if (! $class)
        <x-empty-state icon="calendar-days" tone="yellow" title="We can't tell your class"
            text="Your level and programme don't match a class the school runs, so there's no timetable to show. Check them on your profile, or ask an admin." >
            <x-button href="{{ route('profile.edit') }}" variant="secondary" size="sm" icon="user">Your profile</x-button>
        </x-empty-state>
    @elseif ($count === 0)
        <x-empty-state icon="calendar-days" tone="green" title="No timetable yet"
            :text="$canEdit ? 'Add each lecture, or upload the whole week from the Excel template.' : 'The timetable for '.$class->label().' hasn\'t been put up yet. Your class governor adds it.'">
            @if ($canEdit)
                <x-button href="{{ route('timetable.edit', $classes ? ['class' => $class->key()] : []) }}" size="sm" icon="plus">Add the timetable</x-button>
            @endif
        </x-empty-state>
    @else
        @php
            $todaySlots = $week['days'][$week['today']]['slots'] ?? [];
            $left = collect($todaySlots)->whereNotIn('state', ['done'])->count();
        @endphp

        <div class="tt-today animate-enter mb-8">
            <div class="tt-today-card">
                <x-icon-tile name="clock" tone="green" />
                <div class="min-w-0">
                    <p class="tt-today-label">{{ $week['now'] ? 'On now' : 'Today' }}</p>
                    @if ($week['now'])
                        <p class="mt-0.5 font-display text-lg font-bold">{{ $week['now']->course_code }}</p>
                        <p class="text-sm text-muted">Until {{ \App\Support\Timetables\TimeOfDay::format($week['now']->ends_at) }}@if ($week['now']->venue) · {{ $week['now']->venue }}@endif</p>
                    @elseif ($todaySlots === [])
                        <p class="mt-0.5 font-display text-lg font-bold">No lectures today</p>
                        <p class="text-sm text-muted">Enjoy the free day.</p>
                    @elseif ($left === 0)
                        <p class="mt-0.5 font-display text-lg font-bold">Done for today</p>
                        <p class="text-sm text-muted">{{ count($todaySlots) }} {{ \Illuminate\Support\Str::plural('lecture', count($todaySlots)) }} today, all finished.</p>
                    @else
                        <p class="mt-0.5 font-display text-lg font-bold">{{ $left }} {{ \Illuminate\Support\Str::plural('lecture', $left) }} left</p>
                        <p class="text-sm text-muted">Of {{ count($todaySlots) }} today.</p>
                    @endif
                </div>
            </div>
            @if ($week['next'])
                @php($next = $week['next'])
                <div class="tt-today-card">
                    <x-icon-tile name="calendar-clock" tone="blue" />
                    <div class="min-w-0">
                        <p class="tt-today-label">Next</p>
                        <p class="mt-0.5 font-display text-lg font-bold">{{ $next->course_code }}@if ($next->course_title) <span class="font-sans text-sm font-normal text-muted">{{ $next->course_title }}</span>@endif</p>
                        <p class="text-sm text-muted">{{ $next->day === $week['today'] ? 'Today' : $next->dayName() }}, {{ \App\Support\Timetables\TimeOfDay::format($next->starts_at) }}@if ($next->venue) · {{ $next->venue }}@endif</p>
                    </div>
                </div>
            @endif
        </div>

        <div @class(['tt-week stagger', 'tt-week-6' => count($week['days']) === 6])>
            @foreach ($week['days'] as $number => $day)
                <section @class(['tt-day', 'is-today' => $day['today']]) aria-labelledby="day-{{ $number }}">
                    <h2 id="day-{{ $number }}" class="tt-day-head">
                        <span>{{ $day['name'] }}</span>
                        @if ($day['today'])
                            <x-badge variant="primary">Today</x-badge>
                        @endif
                    </h2>
                    @if ($day['slots'] === [])
                        <p class="tt-free">No lectures</p>
                    @else
                        <ol class="tt-slots">
                            @foreach ($day['slots'] as $item)
                                @php($slot = $item['slot'])
                                <li @class(['tt-slot', 'tt-tone-'.$slot->tone(), 'is-now' => $item['state'] === 'now', 'is-done' => $item['state'] === 'done'])>
                                    <span class="tt-time">
                                        <span>{{ $slot->timeRange() }}</span>
                                        @if ($item['state'] === 'now')
                                            <span class="tt-state">Now</span>
                                        @elseif ($item['state'] === 'next')
                                            <span class="tt-state">Next</span>
                                        @endif
                                    </span>
                                    <span class="tt-code">{{ $slot->course_code }}</span>
                                    @if ($slot->course_title)
                                        <span class="tt-title">{{ $slot->course_title }}</span>
                                    @endif
                                    @if ($slot->venue || $slot->lecturer)
                                        <span class="tt-meta">
                                            @if ($slot->venue)
                                                <span><x-icon name="map-pin" /> {{ $slot->venue }}</span>
                                            @endif
                                            @if ($slot->lecturer)
                                                <span><x-icon name="user" /> {{ $slot->lecturer }}</span>
                                            @endif
                                        </span>
                                    @endif
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </section>
            @endforeach
        </div>
    @endif
</x-layouts.app>
