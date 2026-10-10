@php
    // Only the form that failed gets back what was typed.
    $addFailed = $errors->getBag('add')->any();
    $import = session('import');
@endphp

<x-layouts.admin title="Exam timetable">
    <x-page-header title="Exam timetable" :subtitle="$count.' '.\Illuminate\Support\Str::plural('paper', $count).' · '.($published ? 'visible to students' : 'hidden from students')">
        <x-slot:actions>
            <x-button href="{{ route('exams.index', ['show' => 'all']) }}" variant="secondary" icon="eye">See it as students do</x-button>
        </x-slot:actions>
    </x-page-header>

    @if ($import)
        <x-alert type="success" class="mb-6">
            <p><strong>{{ $import['count'] }} {{ \Illuminate\Support\Str::plural('paper', $import['count']) }} uploaded.</strong> They replace the exam timetable that was here.</p>
            @if ($import['skippedCount'] > 0)
                <details class="mt-2">
                    <summary class="cursor-pointer font-semibold">{{ $import['skippedCount'] }} {{ \Illuminate\Support\Str::plural('row', $import['skippedCount']) }} skipped</summary>
                    <ul class="mt-2 space-y-1 text-sm">
                        @foreach ($import['skipped'] as $row)
                            <li>Row {{ $row['line'] }}: {{ $row['reason'] }} <span class="text-muted">({{ $row['text'] }})</span></li>
                        @endforeach
                    </ul>
                </details>
            @endif
        </x-alert>
    @endif

    <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start">
        <div class="min-w-0 space-y-6">
            @forelse ($days as $date => $exams)
                @php($day = \Illuminate\Support\Carbon::parse($date))
                <x-section :title="$day->format('l j F Y')" id="date-{{ $date }}" class="tt-edit-day"
                    :description="$exams->count().' '.\Illuminate\Support\Str::plural('paper', $exams->count())">
                    <ul class="tt-edit-list">
                        @foreach ($exams as $exam)
                            @php($bag = 'exam-'.$exam->id)
                            @php($failed = $errors->getBag($bag)->any())
                            <li>
                                <details class="tt-edit-row" @if ($failed) open @endif>
                                    <summary>
                                        <span class="tt-swatch"></span>
                                        <span class="min-w-0 flex-1">
                                            <span class="block font-semibold">{{ $exam->course_code }}@if ($exam->course_title) <span class="font-normal text-muted">· {{ $exam->course_title }}</span>@endif</span>
                                            <span class="block text-sm text-muted">{{ $exam->timeRange() }} · {{ $exam->audience() }}@if ($exam->venue) · {{ $exam->venue }}@endif</span>
                                        </span>
                                        <x-icon name="pencil" class="shrink-0 text-muted" />
                                    </summary>
                                    <div class="space-y-3 p-3">
                                        <form method="POST" action="{{ route('exams.update', $exam) }}" class="space-y-3" novalidate>
                                            @csrf
                                            @method('PUT')
                                            <div class="tt-form-grid">
                                                <div class="tt-span-2">
                                                    <x-field name="date" id="{{ $bag }}-date" label="Date" type="date" :value="$exam->date->toDateString()" :bag="$bag" :old="$failed" required />
                                                </div>
                                                <x-field name="starts_at" id="{{ $bag }}-start" label="Starts" type="time" :value="$exam->startInput()" :bag="$bag" :old="$failed" required />
                                                <x-field name="ends_at" id="{{ $bag }}-end" label="Ends" type="time" :value="$exam->endInput()" :bag="$bag" :old="$failed" />
                                                <x-field name="course_code" id="{{ $bag }}-code" label="Course code" :value="$exam->course_code" :bag="$bag" :old="$failed" maxlength="20" required />
                                                <x-field name="venue" id="{{ $bag }}-venue" label="Venue" :value="$exam->venue" :bag="$bag" :old="$failed" maxlength="100" />
                                                <div class="tt-span-2">
                                                    <x-field name="course_title" id="{{ $bag }}-title" label="Course title" :value="$exam->course_title" :bag="$bag" :old="$failed" maxlength="150" />
                                                </div>
                                                <div class="tt-span-2">
                                                    <x-field name="note" id="{{ $bag }}-note" label="Note" :value="$exam->note" :bag="$bag" :old="$failed" maxlength="200" />
                                                </div>
                                            </div>
                                            @include('exams.partials.audience', ['exam' => $exam, 'useOld' => $failed])
                                            <x-button size="sm" icon="circle-check" class="w-full">Save</x-button>
                                        </form>
                                        <form method="POST" action="{{ route('exams.destroy', $exam) }}" data-confirm="Remove {{ $exam->course_code }} from the exam timetable?">
                                            @csrf
                                            @method('DELETE')
                                            <x-button size="sm" variant="ghost" icon="trash-2" class="w-full text-danger">Remove</x-button>
                                        </form>
                                    </div>
                                </details>
                            </li>
                        @endforeach
                    </ul>
                </x-section>
            @empty
                <x-empty-state icon="calendar-days" tone="green" title="No papers yet"
                    text="Add papers one at a time, or upload the whole timetable from the Excel template." />
            @endforelse
        </div>

        <aside class="space-y-4">
            <x-card class="space-y-3" id="visibility">
                <div class="flex items-center gap-3">
                    <x-icon-tile :name="$published ? 'eye' : 'eye-off'" :tone="$published ? 'green' : 'yellow'" size="sm" />
                    <div class="min-w-0">
                        <h2 class="text-base">{{ $published ? 'Visible to students' : 'Hidden from students' }}</h2>
                        <p class="text-sm text-muted">{{ $published ? 'Students see their papers here and on their home page.' : 'Prepare it here; students see "not out yet" until you show it.' }}</p>
                    </div>
                </div>
                <form method="POST" action="{{ route('exams.publish') }}"
                    data-confirm="{{ $published ? 'Hide the exam timetable from students?' : 'Show the exam timetable to every student now?' }}">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="published" value="{{ $published ? 0 : 1 }}">
                    <x-button :variant="$published ? 'secondary' : 'primary'" :icon="$published ? 'eye-off' : 'eye'" class="w-full">
                        {{ $published ? 'Hide from students' : 'Show to students' }}
                    </x-button>
                </form>
            </x-card>

            <x-card class="space-y-4" id="add">
                <div class="flex items-center gap-3">
                    <x-icon-tile name="plus" tone="green" size="sm" />
                    <h2 class="text-base">Add a paper</h2>
                </div>
                <form method="POST" action="{{ route('exams.store') }}" class="space-y-3" novalidate>
                    @csrf
                    <div class="tt-form-grid">
                        <div class="tt-span-2">
                            <x-field name="date" id="add-date" label="Date" type="date" bag="add" :old="$addFailed" required />
                        </div>
                        <x-field name="starts_at" id="add-start" label="Starts" type="time" bag="add" :old="$addFailed" required />
                        <x-field name="ends_at" id="add-end" label="Ends" type="time" bag="add" :old="$addFailed" />
                        <x-field name="course_code" id="add-code" label="Course code" bag="add" :old="$addFailed" maxlength="20" placeholder="CSC 201" required />
                        <x-field name="venue" id="add-venue" label="Venue" bag="add" :old="$addFailed" maxlength="100" placeholder="Hall A" />
                        <div class="tt-span-2">
                            <x-field name="course_title" id="add-title" label="Course title (optional)" bag="add" :old="$addFailed" maxlength="150" />
                        </div>
                        <div class="tt-span-2">
                            <x-field name="note" id="add-note" label="Note (optional)" bag="add" :old="$addFailed" maxlength="200" placeholder="Bring your ID card" />
                        </div>
                    </div>
                    @include('exams.partials.audience', ['useOld' => $addFailed])
                    <x-button icon="plus" class="w-full">Add paper</x-button>
                </form>
            </x-card>

            <x-card class="space-y-4" id="upload">
                <div class="flex items-center gap-3">
                    <x-icon-tile name="sheet" tone="blue" size="sm" />
                    <h2 class="text-base">Upload the timetable</h2>
                </div>
                <form method="POST" action="{{ route('exams.import') }}" enctype="multipart/form-data" class="space-y-3" novalidate
                    data-confirm="Replace the whole exam timetable with this file?">
                    @csrf
                    <div>
                        <label for="exams-file" class="field-label">Exam timetable file</label>
                        <input id="exams-file" name="exams" type="file" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" required class="field-input field-file"
                            @error('exams', 'import') aria-invalid="true" aria-describedby="exams-error" @enderror>
                        @error('exams', 'import')
                            <p id="exams-error" class="field-error">{{ $message }}</p>
                        @enderror
                        @if (session('skipped'))
                            <ul class="mt-2 space-y-0.5 text-xs text-muted">
                                @foreach (session('skipped') as $row)
                                    <li>Row {{ $row['line'] }}: {{ $row['reason'] }}</li>
                                @endforeach
                            </ul>
                        @endif
                        <p class="mt-1.5 text-sm text-muted">Excel (.xlsx) or CSV. It replaces the whole exam timetable.</p>
                    </div>
                    <x-button variant="secondary" icon="upload" class="w-full">Upload</x-button>
                </form>
                <p class="border-t border-border pt-4 text-sm text-muted">
                    Use the template: one paper per row with date, start time, course code and who sits it.
                    <a href="{{ route('exams.template') }}" class="link">Download the template</a>
                </p>
            </x-card>
        </aside>
    </div>
</x-layouts.admin>
