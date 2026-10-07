@php
    $zone = config('app.display_timezone');
    $import = session('import');
    $mismatches = session('mismatches', []);
    $programmes = \App\Enums\Programme::cases();
    $levels = \App\Enums\Level::cases();
    $uploaded = collect($classes)->filter(fn ($class, $key) => ! str_starts_with($key, '|') && ! str_ends_with($key, '|'))->count();
    $unsorted = collect($classes)->filter(fn ($class, $key) => str_starts_with($key, '|') || str_ends_with($key, '|'))->sum('total');
    $programmeOptions = ['' => 'Choose…'] + collect($programmes)->mapWithKeys(fn ($p) => [$p->value => $p->label()])->all();
    $levelOptions = ['' => 'Choose…'] + collect($levels)->mapWithKeys(fn ($l) => [$l->value => $l->label()])->all();
@endphp

<x-layouts.admin title="Nominal roll">
    <x-page-header title="Nominal roll" subtitle="The official list of current students, class by class. Elections can be limited to students on it, so graduates and made-up matric numbers can't vote."
        :back="route('elections.manage')" back-label="Manage elections" />

    @if ($import)
        <x-alert type="success" class="mb-6">
            <p><strong>{{ $import['class'] }}: {{ number_format($import['total']) }} {{ \Illuminate\Support\Str::plural('student', $import['total']) }}.</strong>
                {{ number_format($import['added']) }} added, {{ number_format($import['removed']) }} removed@if ($import['moved']), {{ number_format($import['moved']) }} moved from another class@endif.</p>
            @if ($import['skippedCount'] > 0)
                <details class="mt-2">
                    <summary class="cursor-pointer font-semibold">{{ $import['skippedCount'] }} {{ \Illuminate\Support\Str::plural('line', $import['skippedCount']) }} skipped (no valid matric number)</summary>
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
                <li class="stat-tile"><strong>{{ $uploaded }}<span class="text-muted">/{{ count($programmes) * count($levels) }}</span></strong><small>Classes uploaded</small></li>
            </ul>

            <x-section title="Classes" description="Download a class's template, send it to its governor, then upload the list they return. Uploading a class replaces that class only." icon="users" tone="green">
                <div class="space-y-4">
                    @foreach ($programmes as $p)
                        <div class="roll-programme">
                            <h3 class="roll-programme-title">{{ $p->label() }} <span class="text-sm font-normal text-muted">· matric numbers start with {{ $p->matricLetter() }}/</span></h3>
                            <ul class="roll-classes">
                                @foreach ($levels as $l)
                                    @php($class = $classes[$p->value.'|'.$l->value] ?? null)
                                    <li @class(['roll-class', 'is-empty' => ! $class])>
                                        <div class="min-w-0 flex-1">
                                            <p class="font-semibold">{{ $l->label() }}</p>
                                            <p class="text-xs text-muted">
                                                @if ($class)
                                                    {{ number_format($class['total']) }} {{ \Illuminate\Support\Str::plural('student', $class['total']) }} · {{ \Illuminate\Support\Carbon::parse($class['updated'])->timezone($zone)->format('j M') }}
                                                @else
                                                    Not uploaded
                                                @endif
                                            </p>
                                        </div>
                                        <a href="{{ route('roll.template', ['programme' => $p->value, 'level' => $l->value]) }}" class="icon-btn" aria-label="Template for {{ $l->label() }} {{ $p->label() }}" title="Template"><x-icon name="file-text" /></a>
                                        <a href="{{ route('roll.index', ['programme' => $p->value, 'level' => $l->value]) }}#import" class="icon-btn" aria-label="Upload {{ $l->label() }} {{ $p->label() }}" title="Upload"><x-icon name="upload" /></a>
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

            <x-section title="Find a student" description="Check whether someone is on the roll, and in which class." icon="search" tone="blue">
                <form method="GET" action="{{ route('roll.index') }}" role="search" class="relative">
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
                                    <x-badge class="shrink-0">{{ trim(($entry->level?->label() ?? '').' '.($entry->programme?->label() ?? '')) }}</x-badge>
                                @endif
                            </li>
                        @endforeach
                    </ul>

                    {{ $entries->links('partials.pagination') }}
                @endif
            </x-section>
        </div>

        <aside id="import" class="space-y-4 lg:sticky lg:top-24">
            <x-card class="space-y-4">
                <div class="flex items-center gap-3">
                    <x-icon-tile name="upload" tone="green" size="sm" />
                    <h2 class="text-base">Upload a class</h2>
                </div>

                @if ($votingOpen)
                    <x-alert type="warning">Voting is open in an election limited to the roll. Changes apply to it straight away.</x-alert>
                @endif

                <form method="POST" action="{{ route('roll.store') }}" enctype="multipart/form-data" class="space-y-4" novalidate>
                    @csrf
                    <div class="grid grid-cols-2 gap-3">
                        <x-select name="programme" label="Programme" :options="$programmeOptions" :value="$programme?->value" />
                        <x-select name="level" label="Level" :options="$levelOptions" :value="$level?->value" />
                    </div>

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
                        <p class="mt-1.5 text-sm text-muted">Excel (.xlsx) or CSV, up to 5 MB. The class's current list is replaced by this one.</p>
                    </div>

                    <x-button icon="upload" class="w-full">Upload class</x-button>
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
        </aside>
    </div>
</x-layouts.admin>
