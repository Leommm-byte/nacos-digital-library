@php
    $zone = config('app.display_timezone');
    $mismatches = session('mismatches', []);
    $programmes = ['' => 'Choose…'] + collect(\App\Enums\Programme::cases())->mapWithKeys(fn ($p) => [$p->value => $p->label()])->all();
    $levels = ['' => 'Choose…'] + collect(\App\Enums\Level::cases())->mapWithKeys(fn ($l) => [$l->value => $l->label()])->all();
@endphp

<x-layouts.vote-admin title="Nominal roll">
    <x-page-header title="Nominal roll" subtitle="The department's official list of students, class by class. Only students on it can register and vote." />

    @if ($lockedBy)
        <x-alert type="warning" class="mb-6">
            <strong>The roll is locked while voting is open</strong> in <a href="{{ route('vote.admin.elections.show', $lockedBy) }}" class="link">{{ $lockedBy->title }}</a>, so nobody can be added or removed to sway the vote. It unlocks when voting closes{{ $lockedBy->ends_at ? ' ('.$lockedBy->ends_at->timezone($zone)->format('D j M, g:i a').')' : '' }}.
        </x-alert>
    @endif

    @error('roll')
        <x-alert type="error" class="mb-6">{{ $message }}</x-alert>
    @enderror

    <div class="grid grid-cols-1 gap-8 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start">
        <div class="min-w-0 space-y-8">
            <ul class="grid grid-cols-3 gap-3">
                <li class="stat-tile"><strong>{{ number_format($total) }}</strong><small>On the roll</small></li>
                <li class="stat-tile"><strong>{{ number_format($registered) }}</strong><small>Registered</small></li>
                <li class="stat-tile"><strong>{{ count($classes) }}</strong><small>{{ \Illuminate\Support\Str::plural('Class', count($classes)) }}</small></li>
            </ul>

            @if ($classes !== [])
                <x-section title="Classes" description="Uploading a class again replaces that class only." icon="users" tone="green">
                    <ul class="roll-classes">
                        @foreach ($classes as $class)
                            <li class="roll-class">
                                <div class="min-w-0">
                                    <p class="font-semibold">{{ $class['label'] }}</p>
                                    <p class="text-xs text-muted">{{ number_format($class['total']) }} {{ \Illuminate\Support\Str::plural('student', $class['total']) }}</p>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </x-section>
            @endif

            <x-section id="find" class="scroll-mt-24" title="Find a student" description="Check whether someone is on the roll, and in which class." icon="search" tone="blue">
                <form method="GET" action="{{ route('vote.admin.roll') }}#find" role="search" class="relative">
                    <label for="roll-search" class="sr-only">Find a student on the roll</label>
                    <x-icon name="search" class="pointer-events-none absolute top-1/2 left-4 -translate-y-1/2 text-muted" />
                    <input id="roll-search" name="q" type="search" value="{{ $search }}" placeholder="Matric number or name" class="field-input pl-11">
                </form>

                @if ($entries->isEmpty())
                    @if ($search !== '')
                        <x-empty-state class="mt-4" icon="search" title="Not on the roll" text="No student on the roll matches “{{ $search }}”. They can't register until they're added." />
                    @else
                        <x-empty-state class="mt-4" icon="users" tone="green" title="The roll is empty" text="Upload each class's list. Students can only register once they're on it." />
                    @endif
                @else
                    <ul class="roll-list mt-4">
                        @foreach ($entries as $entry)
                            <li>
                                <span class="roll-matric">{{ $entry->matric_number }}</span>
                                <span class="min-w-0 flex-1 truncate">{{ $entry->fullname ?? '—' }}</span>
                                @if ($entry->classLabel() !== '')
                                    <x-badge class="shrink-0">{{ $entry->classLabel() }}</x-badge>
                                @endif
                                @unless ($lockedBy)
                                    <form method="POST" action="{{ route('vote.admin.roll.students.destroy', ['entry' => $entry->id, 'q' => $search ?: null, 'page' => $entries->currentPage() > 1 ? $entries->currentPage() : null]) }}" data-confirm="Take {{ $entry->matric_number }}{{ $entry->fullname ? ' ('.$entry->fullname.')' : '' }} off the roll? They won't be able to vote.">
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

                <form method="POST" action="{{ route('vote.admin.roll.store') }}" enctype="multipart/form-data" novalidate>
                    @csrf
                    <fieldset class="space-y-4" @disabled($lockedBy)>
                        <div class="grid grid-cols-2 gap-3">
                            <x-select name="programme" label="Programme" :options="$programmes" />
                            <x-select name="level" label="Level" :options="$levels" />
                        </div>

                        <x-file-input name="roll" label="Class list" icon="sheet" button="Choose the list" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" required
                            hint="Excel (.xlsx) or CSV, up to 5 MB.">
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
                        </x-file-input>

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
                    <p>Use the template: the class at the top, then one student per row with matric number, full name and email (optional). <a href="{{ route('vote.admin.roll.template') }}" class="link">Download the template</a></p>
                </div>
            </x-card>

            <x-card class="space-y-4" id="add-student">
                <div class="flex items-center gap-3">
                    <x-icon-tile name="user-plus" tone="blue" size="sm" />
                    <h2 class="text-base">Add one student</h2>
                </div>
                <form method="POST" action="{{ route('vote.admin.roll.students.store') }}" novalidate>
                    @csrf
                    <fieldset class="space-y-3" @disabled($lockedBy)>
                        <x-field name="matric_number" id="add-matric" label="Matric number" bag="student" required maxlength="32" placeholder="F/ND/24/1234567" autocomplete="off" />
                        <x-field name="fullname" id="add-name" label="Full name" bag="student" required maxlength="150" placeholder="Surname first" autocomplete="off" />
                        <x-field name="email" id="add-email" label="Email" type="email" bag="student" maxlength="255" autocomplete="off" />
                        <div class="grid grid-cols-2 gap-3">
                            <x-select name="programme" id="add-programme" label="Programme" :options="$programmes" bag="student" />
                            <x-select name="level" id="add-level" label="Level" :options="$levels" bag="student" />
                        </div>
                        <x-button size="sm" variant="secondary" icon="user-plus" class="w-full">Add to the roll</x-button>
                    </fieldset>
                </form>
                <p class="text-xs text-muted">For a late registration, or someone left off their class list.</p>
            </x-card>
        </aside>
    </div>
</x-layouts.vote-admin>
