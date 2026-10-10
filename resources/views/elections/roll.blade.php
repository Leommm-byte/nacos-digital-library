@php
    $zone = config('app.display_timezone');
    $import = session('import');
    $mismatches = session('mismatches', []);
    $schoolClasses = \App\Support\Classes\SchoolClass::all();
    $byProgramme = collect($schoolClasses)->groupBy(fn ($c) => $c->programme->value);
    $uploaded = count($classes);
    $classOptions = ['' => 'Choose…'] + collect($schoolClasses)->mapWithKeys(fn ($c) => [$c->key() => $c->label()])->all();
@endphp

<x-layouts.admin title="Nominal roll">
    <x-page-header title="Nominal roll" subtitle="The official list of current students, class by class. Elections can be limited to students on it, so graduates and made-up matric numbers can't vote."
        :back="route('elections.manage')" back-label="Manage elections" />

    @if ($lockedBy)
        <x-alert type="warning" class="mb-6">
            <strong>The roll is locked while voting is open</strong> in <a href="{{ route('elections.manage.show', $lockedBy) }}" class="link">{{ $lockedBy->title }}</a>, so nobody can be added or removed to sway the vote. It unlocks when voting closes{{ $lockedBy->ends_at ? ' ('.$lockedBy->ends_at->timezone($zone)->format('D j M, g:i a').')' : '' }}.
        </x-alert>
    @endif

    @if ($import)
        <x-alert type="success" class="mb-6">
            <p><strong>{{ $import['class'] }}: {{ number_format($import['total']) }} {{ \Illuminate\Support\Str::plural('student', $import['total']) }}.</strong>
                {{ number_format($import['added']) }} added{{ ($import['replace'] ?? true) ? ', '.number_format($import['removed']).' removed' : '' }}{{ $import['moved'] ? ', '.number_format($import['moved']).' moved from another class' : '' }}.</p>
            @if ($import['skippedCount'] > 0)
                <details class="mt-2">
                    <summary class="cursor-pointer font-semibold">{{ $import['skippedCount'] }} {{ \Illuminate\Support\Str::plural('line', $import['skippedCount']) }} skipped (no valid matric number: 7 digits and an entry year that has started)</summary>
                    <ul class="mt-2 space-y-1 text-sm">
                        @foreach ($import['skipped'] as $line)
                            <li>Line {{ $line['line'] }}: {{ $line['text'] }}</li>
                        @endforeach
                        @if ($import['skippedCount'] > count($import['skipped']))
                            <li>…and {{ $import['skippedCount'] - count($import['skipped']) }} more.</li>
                        @endif
                    </ul>
                </details>
            @endif
        </x-alert>
    @endif

    <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start">
        <div class="min-w-0 space-y-8">
            <ul class="grid grid-cols-3 gap-3">
                <li class="stat-tile"><strong>{{ number_format($total) }}</strong><small>On the roll</small></li>
                <li class="stat-tile"><strong>{{ number_format($withAccount) }}</strong><small>Have an account</small></li>
                <li class="stat-tile"><strong>{{ $uploaded }}<span class="text-muted">/{{ count($schoolClasses) }}</span></strong><small>Classes uploaded</small></li>
            </ul>

            <x-section title="Classes" description="Download a class's template, send it to its governor, then upload the list they return. Uploading a class replaces that class only." icon="users" tone="green">
                <div class="space-y-4">
                    @foreach ($byProgramme as $programmeClasses)
                        @php($p = $programmeClasses->first()->programme)
                        <div class="roll-programme">
                            <h3 class="roll-programme-title">{{ $p->label() }} <span class="text-sm font-normal text-muted">· matric numbers start with {{ $p->matricLetter() }}/</span></h3>
                            <ul class="roll-classes">
                                @foreach ($programmeClasses as $c)
                                    @php($class = $classes[$c->key()] ?? null)
                                    <li @class(['roll-class', 'is-empty' => ! $class])>
                                        <div class="min-w-0 flex-1">
                                            <p class="font-semibold">{{ $c->shortLabel() }}</p>
                                            <p class="text-xs text-muted">
                                                @if ($class)
                                                    {{ number_format($class['total']) }} {{ \Illuminate\Support\Str::plural('student', $class['total']) }} · {{ \Illuminate\Support\Carbon::parse($class['updated'])->timezone($zone)->format('j M') }}
                                                @else
                                                    Not uploaded
                                                @endif
                                            </p>
                                        </div>
                                        <a href="{{ route('roll.template', ['class' => $c->key()]) }}" class="icon-btn" aria-label="Template for {{ $c->label() }}" title="Template"><x-icon name="file-text" /></a>
                                        <a href="{{ route('roll.index', ['class' => $c->key()]) }}#import" class="icon-btn" aria-label="Upload {{ $c->label() }}" title="Upload"><x-icon name="upload" /></a>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
                @if ($unsorted > 0)
                    <p class="mt-3 text-sm text-muted">{{ number_format($unsorted) }} {{ \Illuminate\Support\Str::plural('student', $unsorted) }} from an earlier import have no class yet. Upload their class to sort them.</p>
                @endif
            </x-section>

            <x-section id="find" class="scroll-mt-24" title="Find a student" description="Check whether someone is on the roll, and in which class." icon="search" tone="blue">
                <form method="GET" action="{{ route('roll.index') }}#find" role="search" class="relative">
                    <label for="roll-search" class="sr-only">Find a student on the roll</label>
                    <x-icon name="search" class="pointer-events-none absolute top-1/2 left-4 -translate-y-1/2 text-muted" />
                    <input id="roll-search" name="q" type="search" value="{{ $search }}" placeholder="Matric number or name" class="field-input pl-11">
                </form>

                @if ($entries->isEmpty())
                    @if ($search !== '')
                        <x-empty-state class="mt-4" icon="search" title="Not on the roll" text="No student on the roll matches “{{ $search }}”. A student who isn't on it can't vote in elections limited to the roll." />
                    @else
                        <x-empty-state class="mt-4" icon="users" tone="green" title="The roll is empty" text="Upload each class's list to limit elections to real, current students." />
                    @endif
                @else
                    <ul class="roll-list mt-4">
                        @foreach ($entries as $entry)
                            <li>
                                <span class="roll-matric">{{ $entry->matric_number }}</span>
                                <span class="min-w-0 flex-1 truncate">{{ $entry->fullname ?? '—' }}</span>
                                @if ($entry->level || $entry->programme)
                                    <x-badge class="shrink-0">{{ $entry->classLabel() }}</x-badge>
                                @endif
                                @unless ($lockedBy)
                                    <form method="POST" action="{{ route('roll.students.destroy', ['entry' => $entry, 'q' => $search ?: null, 'page' => $entries->currentPage() > 1 ? $entries->currentPage() : null]) }}" data-confirm="Take {{ $entry->matric_number }}{{ $entry->fullname ? ' ('.$entry->fullname.')' : '' }} off the roll? They won't be able to vote in elections limited to the roll.">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="icon-btn icon-btn-danger" aria-label="Remove {{ $entry->matric_number }} from the roll"><x-icon name="trash-2" /></button>
                                    </form>
                                @endunless
                            </li>
                        @endforeach
                    </ul>

                    {{ $entries->links('partials.pagination') }}
                @endif
            </x-section>
        </div>

        <aside id="import" class="space-y-4">
            <x-card class="space-y-4">
                <div class="flex items-center gap-3">
                    <x-icon-tile name="upload" tone="green" size="sm" />
                    <h2 class="text-base">Upload a class</h2>
                </div>

                <form method="POST" action="{{ route('roll.store') }}" enctype="multipart/form-data" novalidate>
                    @csrf
                    <fieldset class="space-y-4" @disabled($lockedBy)>
                    <x-select name="class" label="Class" :options="$classOptions" :value="$selected?->key()" />

                    <div>
                        <label for="roll" class="field-label">Class list</label>
                        <input id="roll" name="roll" type="file" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" required class="field-input field-file"
                            @error('roll') aria-invalid="true" aria-describedby="roll-error" @enderror>
                        @error('roll')
                            <p id="roll-error" class="field-error">{{ $message }}</p>
                        @enderror
                        @if ($mismatches !== [])
                            <ul class="mt-2 space-y-0.5 text-xs text-muted">
                                @foreach ($mismatches as $matric)
                                    <li>{{ $matric }}</li>
                                @endforeach
                            </ul>
                            <label class="mt-3 flex items-start gap-3 text-sm">
                                <input type="checkbox" name="confirm" value="1" class="mt-0.5 size-4 accent-[var(--primary)]">
                                <span>Import anyway: I've checked, this is the right list for the class.</span>
                            </label>
                        @endif
                        <p class="mt-1.5 text-sm text-muted">Excel (.xlsx) or CSV, up to 5 MB.</p>
                    </div>

                    <fieldset class="space-y-2">
                        <legend class="field-label">This file is</legend>
                        <label class="flex items-start gap-3">
                            <input type="radio" name="mode" value="replace" class="mt-1 size-4 accent-[var(--primary)]" @checked(old('mode', 'replace') === 'replace')>
                            <span class="text-sm"><strong>The class's full list</strong><span class="block text-muted">Replaces it: students not in the file come off the roll.</span></span>
                        </label>
                        <label class="flex items-start gap-3">
                            <input type="radio" name="mode" value="add" class="mt-1 size-4 accent-[var(--primary)]" @checked(old('mode') === 'add')>
                            <span class="text-sm"><strong>Extra students</strong><span class="block text-muted">Added to the class; nobody is removed.</span></span>
                        </label>
                    </fieldset>

                    <x-button icon="upload" class="w-full">Upload</x-button>
                    </fieldset>
                </form>

                <div class="border-t border-border pt-4 text-sm text-muted">
                    <p>Use the template: the class at the top, then one student per row with matric number, full name and email (optional). <a href="{{ route('roll.template') }}" class="link">Blank template</a></p>
                </div>

                @if ($lastImport)
                    <p class="text-xs text-muted">
                        Last upload: {{ $lastImport->meta['class'] ?? 'a class' }}, {{ $lastImport->created_at->timezone($zone)->format('j M Y, g:i a') }}@if ($lastImport->user) by {{ $lastImport->user->fullname }}@endif.
                    </p>
                @endif
            </x-card>

            <x-card class="space-y-4" id="add-student">
                <div class="flex items-center gap-3">
                    <x-icon-tile name="user-plus" tone="blue" size="sm" />
                    <h2 class="text-base">Add one student</h2>
                </div>
                <form method="POST" action="{{ route('roll.students.store') }}" novalidate>
                    @csrf
                    <fieldset class="space-y-3" @disabled($lockedBy)>
                        <x-field name="matric_number" id="add-matric" label="Matric number" bag="student" required maxlength="32" placeholder="F/ND/24/1234567" autocomplete="off" />
                        <x-field name="fullname" id="add-name" label="Full name" bag="student" required maxlength="150" placeholder="Surname first" autocomplete="off" />
                        <x-field name="email" id="add-email" label="Email (optional)" type="email" bag="student" maxlength="255" autocomplete="off" />
                        <x-select name="class" id="add-class" label="Class" :options="$classOptions" :value="$selected?->key()" />
                        @if ($errors->getBag('student')->has('class'))
                            <p class="field-error">{{ $errors->getBag('student')->first('class') }}</p>
                        @endif
                        @if (session('confirmStudent'))
                            <label class="flex items-start gap-3 text-sm">
                                <input type="checkbox" name="confirm" value="1" class="mt-0.5 size-4 accent-[var(--primary)]">
                                <span>It's right: add them to this class anyway.</span>
                            </label>
                        @endif
                        <x-button size="sm" variant="secondary" icon="user-plus" class="w-full">Add to the roll</x-button>
                    </fieldset>
                </form>
                <p class="text-xs text-muted">For a late registration, or someone left off their class list. Remove a student with the bin next to them under "Find a student".</p>
            </x-card>
        </aside>
    </div>
</x-layouts.admin>
