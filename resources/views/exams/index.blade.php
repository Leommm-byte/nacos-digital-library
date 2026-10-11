<x-layouts.app title="Exam timetable">
    @if ($hidden)
        <x-page-header title="Exam timetable">
            <x-slot:actions>
                <x-button href="{{ route('timetable.show') }}" variant="secondary" icon="calendar-clock">Class timetable</x-button>
            </x-slot:actions>
        </x-page-header>
        @if ($graduated ?? false)
            <x-empty-state icon="graduation-cap" tone="green" title="You've graduated"
                text="Congratulations! Exam timetables are for current students. The library is still yours to read." />
        @else
            <x-empty-state icon="calendar-days" tone="green" title="The exam timetable isn't out yet"
                text="It will appear here once it's confirmed, with your own papers first." />
        @endif
    @else
    @php
        // An admin looks at one programme at a time; links keep it.
        $base = $programmes ? ['programme' => $programme->value] : [];
    @endphp
    <x-page-header title="Exam timetable" :subtitle="$programme->label().' · '.($past ? 'every paper this session' : 'papers from today on')">
        <x-slot:actions>
            @if (Route::has('timetable.show'))
                <x-button href="{{ route('timetable.show') }}" variant="secondary" icon="calendar-clock">Class timetable</x-button>
            @endif
            @can('manage-exams')
                <x-button href="{{ route('exams.manage', ['programme' => $programme->value]) }}" icon="pencil">Manage</x-button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    @if ($programmes)
        <nav class="review-tabs mb-4" aria-label="Programme">
            @foreach ($programmes as $p)
                <a href="{{ route('exams.index', ['programme' => $p->value, 'show' => 'all']) }}" class="review-tab" @if ($p === $programme) aria-current="page" @endif>{{ $p->label() }}</a>
            @endforeach
        </nav>
    @endif

    @if (! $published)
        <x-alert type="warning" class="mb-6">
            <strong>{{ $programme->label() }} students can't see this yet.</strong> You're seeing it as an admin. Show it to them from <a href="{{ route('exams.manage', ['programme' => $programme->value]) }}" class="link">Manage</a> once it's confirmed.
        </x-alert>
    @endif

    @if ($counts['all'] === 0)
        <x-empty-state icon="calendar-days" tone="green" title="No exams on the timetable"
            :text="$hasPast ? 'Every paper has been written. Well done!' : 'The exam timetable will appear here once it\'s out.'">
            @if ($hasPast)
                <x-button href="{{ route('exams.index', [...$base, 'past' => 1]) }}" variant="secondary" size="sm" icon="clock">Show past papers</x-button>
            @endif
        </x-empty-state>
    @else
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <nav class="review-tabs" aria-label="Show">
                @foreach (['mine' => 'Your papers', 'all' => 'Everyone'] as $value => $label)
                    <a href="{{ route('exams.index', array_filter([...$base, 'show' => $value, 'past' => $past ? 1 : null])) }}" class="review-tab" @if ($show === $value) aria-current="page" @endif>
                        {{ $label }} <span class="review-tab-count">{{ number_format($counts[$value]) }}</span>
                    </a>
                @endforeach
            </nav>
            @if ($past)
                <a href="{{ route('exams.index', [...$base, 'show' => $show]) }}" class="link text-sm">Hide past papers</a>
            @elseif ($hasPast)
                <a href="{{ route('exams.index', [...$base, 'show' => $show, 'past' => 1]) }}" class="link text-sm">Show past papers</a>
            @endif
        </div>

        @if ($days->isEmpty())
            <x-empty-state icon="calendar-days" tone="green" title="None of your papers are listed"
                text="Nothing on the timetable is for your level, programme or course. Check everyone's papers in case yours is listed differently.">
                <x-button href="{{ route('exams.index', [...$base, 'show' => 'all']) }}" variant="secondary" size="sm">Show everyone's</x-button>
            </x-empty-state>
        @else
            <div class="exam-days stagger">
                @foreach ($days as $date => $exams)
                    @php($day = \Illuminate\Support\Carbon::parse($date, config('app.display_timezone')))
                    @php($isToday = $day->isSameDay($today))
                    <section class="exam-day" aria-labelledby="date-{{ $date }}">
                        <h2 id="date-{{ $date }}" @class(['exam-date', 'is-today' => $isToday])>
                            <span class="exam-date-day">{{ $day->format('j') }}</span>
                            <span class="exam-date-sub">{{ $day->format('D, M') }}</span>
                            @if ($isToday)
                                <x-badge variant="primary" class="sm:mt-1 sm:self-center">Today</x-badge>
                            @elseif ($day->greaterThan($today) && $day->diffInDays($today, true) <= 7)
                                <span class="exam-date-sub sm:mt-1">in {{ (int) $day->diffInDays($today, true) }} {{ \Illuminate\Support\Str::plural('day', (int) $day->diffInDays($today, true)) }}</span>
                            @endif
                        </h2>
                        <ul class="exam-papers">
                            @foreach ($exams as $exam)
                                <li @class(['exam-card', 'is-mine' => $show === 'all' && in_array($exam->id, $mineIds, true), 'is-past' => $day->lessThan($today)])>
                                    <span class="flex items-center justify-between gap-2 text-sm font-semibold text-muted">
                                        <span class="inline-flex items-center gap-1.5"><x-icon name="clock" class="size-4" /> {{ $exam->timeRange() }}</span>
                                        <x-badge :variant="in_array($exam->id, $mineIds, true) ? 'primary' : 'neutral'" class="shrink-0">{{ $exam->audience() }}</x-badge>
                                    </span>
                                    <span class="mt-1 font-display text-lg font-bold">{{ $exam->course_code }}</span>
                                    @if ($exam->course_title)
                                        <span class="text-sm">{{ $exam->course_title }}</span>
                                    @endif
                                    <span class="tt-meta">
                                        {{-- Venues are often only known on the day. --}}
                                        <span><x-icon name="map-pin" /> {{ $exam->venue ?? 'Venue to be announced' }}</span>
                                        @if ($exam->note)
                                            <span><x-icon name="info" /> {{ $exam->note }}</span>
                                        @endif
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endforeach
            </div>
        @endif
    @endif
    @endif
</x-layouts.app>
