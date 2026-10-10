@php
    // Only the form that failed gets back what was typed.
    $addFailed = $errors->getBag('add')->any();
    $import = session('import');
    $dayOptions = ['' => 'Choose…'] + \App\Models\TimetableSlot::DAYS;
    $query = $classes ? ['class' => $class->key()] : [];
    // Admins edit any class from the admin area; a governor edits their own.
    $layout = $classes ? 'layouts.admin' : 'layouts.app';
@endphp

<x-dynamic-component :component="$layout" title="Edit timetable">
    <x-page-header title="Edit timetable" :subtitle="$class->label().' · '.$count.' '.\Illuminate\Support\Str::plural('lecture', $count)"
        :back="route('timetable.show', $query)" back-label="Timetable" />

    @if ($classes)
        <form method="GET" action="{{ route('timetable.edit') }}" class="mb-6 max-w-sm" data-autosubmit>
            <x-select name="class" id="timetable-class" label="Class" :options="$classes" :value="$class->key()" :old="false" />
            <noscript><x-button variant="secondary" size="sm" class="mt-2">Show</x-button></noscript>
        </form>
    @endif

    @if ($import)
        <x-alert type="success" class="mb-6">
            <p><strong>{{ $import['count'] }} {{ \Illuminate\Support\Str::plural('lecture', $import['count']) }} uploaded.</strong> They replace the timetable that was here.</p>
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
            @if ($count === 0)
                <x-empty-state icon="calendar-days" tone="green" title="No lectures yet"
                    text="Add them one at a time, or upload the whole week from the Excel template." />
            @endif

            @foreach (\App\Models\TimetableSlot::DAYS as $number => $name)
                @php($daySlots = $slots->get($number, collect()))
                @if ($daySlots->isNotEmpty() || $number <= 5)
                    <x-section :title="$name" id="day-{{ $number }}" class="tt-edit-day"
                        :description="$daySlots->isEmpty() ? 'No lectures.' : $daySlots->count().' '.\Illuminate\Support\Str::plural('lecture', $daySlots->count()).'. Tap one to change it.'">
                        @if ($daySlots->isNotEmpty())
                            <ul class="tt-edit-list">
                                @foreach ($daySlots as $slot)
                                    @php($bag = 'slot-'.$slot->id)
                                    @php($failed = $errors->getBag($bag)->any())
                                    <li>
                                        <details class="tt-edit-row tt-tone-{{ $slot->tone() }}" @if ($failed) open @endif>
                                            <summary>
                                                <span class="tt-swatch"></span>
                                                <span class="min-w-0 flex-1">
                                                    <span class="block font-semibold">{{ $slot->course_code }}@if ($slot->course_title) <span class="font-normal text-muted">· {{ $slot->course_title }}</span>@endif</span>
                                                    <span class="block text-sm text-muted">{{ $slot->timeRange() }}@if ($slot->venue) · {{ $slot->venue }}@endif</span>
                                                </span>
                                                <x-icon name="pencil" class="shrink-0 text-muted" />
                                            </summary>
                                            <div class="space-y-3 p-3">
                                                <form method="POST" action="{{ route('timetable.slots.update', $slot) }}" class="space-y-3" novalidate>
                                                    @csrf
                                                    @method('PUT')
                                                    <div class="tt-form-grid">
                                                        <div class="tt-span-2">
                                                            <x-select name="day" id="{{ $bag }}-day" label="Day" :options="\App\Models\TimetableSlot::DAYS" :value="$slot->day" :bag="$bag" :old="$failed" />
                                                        </div>
                                                        <x-field name="starts_at" id="{{ $bag }}-start" label="Starts" type="time" :value="$slot->startInput()" :bag="$bag" :old="$failed" required />
                                                        <x-field name="ends_at" id="{{ $bag }}-end" label="Ends" type="time" :value="$slot->endInput()" :bag="$bag" :old="$failed" required />
                                                        <x-field name="course_code" id="{{ $bag }}-code" label="Course code" :value="$slot->course_code" :bag="$bag" :old="$failed" maxlength="20" required />
                                                        <x-field name="venue" id="{{ $bag }}-venue" label="Venue" :value="$slot->venue" :bag="$bag" :old="$failed" maxlength="100" />
                                                        <div class="tt-span-2">
                                                            <x-field name="course_title" id="{{ $bag }}-title" label="Course title" :value="$slot->course_title" :bag="$bag" :old="$failed" maxlength="150" />
                                                        </div>
                                                        <div class="tt-span-2">
                                                            <x-field name="lecturer" id="{{ $bag }}-lecturer" label="Lecturer" :value="$slot->lecturer" :bag="$bag" :old="$failed" maxlength="150" />
                                                        </div>
                                                    </div>
                                                    <x-button size="sm" icon="circle-check" class="w-full">Save</x-button>
                                                </form>
                                                <form method="POST" action="{{ route('timetable.slots.destroy', $slot) }}" data-confirm="Remove {{ $slot->course_code }} on {{ $name }}?">
                                                    @csrf
                                                    @method('DELETE')
                                                    <x-button size="sm" variant="ghost" icon="trash-2" class="w-full text-danger">Remove</x-button>
                                                </form>
                                            </div>
                                        </details>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </x-section>
                @endif
            @endforeach
        </div>

        <aside class="space-y-4">
            <x-card class="space-y-4" id="add">
                <div class="flex items-center gap-3">
                    <x-icon-tile name="plus" tone="green" size="sm" />
                    <h2 class="text-base">Add a lecture</h2>
                </div>
                <form method="POST" action="{{ route('timetable.slots.store') }}" class="space-y-3" novalidate>
                    @csrf
                    <input type="hidden" name="class" value="{{ $class->key() }}">
                    <div class="tt-form-grid">
                        <div class="tt-span-2">
                            <x-select name="day" id="add-day" label="Day" :options="$dayOptions" bag="add" :old="$addFailed" />
                        </div>
                        <x-field name="starts_at" id="add-start" label="Starts" type="time" bag="add" :old="$addFailed" required />
                        <x-field name="ends_at" id="add-end" label="Ends" type="time" bag="add" :old="$addFailed" required />
                        <x-field name="course_code" id="add-code" label="Course code" bag="add" :old="$addFailed" maxlength="20" placeholder="CSC 201" required />
                        <x-field name="venue" id="add-venue" label="Venue" bag="add" :old="$addFailed" maxlength="100" placeholder="Lab 2" />
                        <div class="tt-span-2">
                            <x-field name="course_title" id="add-title" label="Course title (optional)" bag="add" :old="$addFailed" maxlength="150" />
                        </div>
                        <div class="tt-span-2">
                            <x-field name="lecturer" id="add-lecturer" label="Lecturer (optional)" bag="add" :old="$addFailed" maxlength="150" />
                        </div>
                    </div>
                    <x-button icon="plus" class="w-full">Add lecture</x-button>
                </form>
            </x-card>

            <x-card class="space-y-4" id="upload">
                <div class="flex items-center gap-3">
                    <x-icon-tile name="sheet" tone="blue" size="sm" />
                    <h2 class="text-base">Upload the week</h2>
                </div>
                <form method="POST" action="{{ route('timetable.import') }}" enctype="multipart/form-data" class="space-y-3" novalidate
                    data-confirm="Replace the whole timetable for {{ $class->label() }} with this file?">
                    @csrf
                    <input type="hidden" name="class" value="{{ $class->key() }}">
                    <div>
                        <label for="timetable-file" class="field-label">Timetable file</label>
                        <input id="timetable-file" name="timetable" type="file" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" required class="field-input field-file"
                            @error('timetable', 'import') aria-invalid="true" aria-describedby="timetable-error" @enderror>
                        @error('timetable', 'import')
                            <p id="timetable-error" class="field-error">{{ $message }}</p>
                        @enderror
                        @if (session('skipped'))
                            <ul class="mt-2 space-y-0.5 text-xs text-muted">
                                @foreach (session('skipped') as $row)
                                    <li>Row {{ $row['line'] }}: {{ $row['reason'] }}</li>
                                @endforeach
                            </ul>
                        @endif
                        <p class="mt-1.5 text-sm text-muted">Excel (.xlsx) or CSV. It replaces this class's whole timetable.</p>
                    </div>
                    <x-button variant="secondary" icon="upload" class="w-full">Upload</x-button>
                </form>
                <p class="border-t border-border pt-4 text-sm text-muted">
                    Use the template: one lecture per row with day, start, end and course code.
                    <a href="{{ route('timetable.template', $query) }}" class="link">Download the template</a>
                </p>
            </x-card>
        </aside>
    </div>
</x-dynamic-component>
