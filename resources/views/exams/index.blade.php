<x-layouts.app title="Exam timetable">
    <x-page-header title="Exam timetable" :subtitle="$past ? 'Every paper this session' : 'Papers from today on'">
        <x-slot:actions>
            @if (Route::has('timetable.show'))
                <x-button href="{{ route('timetable.show') }}" variant="secondary" icon="calendar-clock">Class timetable</x-button>
            @endif
            @can('manage-exams')
                <x-button href="{{ route('exams.manage') }}" icon="pencil">Manage</x-button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    @if ($counts['all'] === 0)
        <x-empty-state icon="calendar-days" tone="green" title="No exams on the timetable"
            :text="$hasPast ? 'Every paper has been written. Well done!' : 'The exam timetable will appear here once it\'s out.'">
            @if ($hasPast)
                <x-button href="{{ route('exams.index', ['past' => 1]) }}" variant="secondary" size="sm" icon="clock">Show past papers</x-button>
            @endif
        </x-empty-state>
    @else
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <nav class="review-tabs" aria-label="Show">
                @foreach (['mine' => 'Your papers', 'all' => 'Everyone'] as $value => $label)
                    <a href="{{ route('exams.index', array_filter(['show' => $value, 'past' => $past ? 1 : null])) }}" class="review-tab" @if ($show === $value) aria-current="page" @endif>
                        {{ $label }} <span class="review-tab-count">{{ number_format($counts[$value]) }}</span>
                    </a>
                @endforeach
            </nav>
            @if ($past)
                <a href="{{ route('exams.index', ['show' => $show]) }}" class="link text-sm">Hide past papers</a>
            @elseif ($hasPast)
                <a href="{{ route('exams.index', ['show' => $show, 'past' => 1]) }}" class="link text-sm">Show past papers</a>
            @endif
        </div>

        @if ($days->isEmpty())
            <x-empty-state icon="calendar-days" tone="green" title="None of your papers are listed"
                text="Nothing on the timetable is for your level, programme or course. Check everyone's papers in case yours is listed differently.">
                <x-button href="{{ route('exams.index', ['show' => 'all']) }}" variant="secondary" size="sm">Show everyone's</x-button>
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
                                    @if ($exam->venue || $exam->note)
                                        <span class="tt-meta">
                                            @if ($exam->venue)
                                                <span><x-icon name="map-pin" /> {{ $exam->venue }}</span>
                                            @endif
                                            @if ($exam->note)
                                                <span><x-icon name="info" /> {{ $exam->note }}</span>
                                            @endif
                                        </span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endforeach
            </div>
        @endif
    @endif
</x-layouts.app>
