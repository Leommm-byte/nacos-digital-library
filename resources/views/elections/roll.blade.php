@php
    $zone = config('app.display_timezone');
    $import = session('import');
@endphp

<x-layouts.app title="Nominal roll">
    <x-page-header title="Nominal roll" subtitle="The official list of current students. Elections can be limited to students on it, so graduates and made-up matric numbers can't vote."
        :back="route('elections.manage')" back-label="Manage elections" />

    @if ($import)
        <x-alert type="success" class="mb-6">
            <p><strong>{{ number_format($import['total']) }} {{ \Illuminate\Support\Str::plural('student', $import['total']) }} on the roll.</strong>
                {{ number_format($import['added']) }} added, {{ number_format($import['removed']) }} removed.</p>
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
        <div class="min-w-0 space-y-6">
            <ul class="grid grid-cols-2 gap-3">
                <li class="shortcut"><x-icon-tile name="users" tone="green" />
                    <span><strong>{{ number_format($total) }}</strong><small>On the roll</small></span></li>
                <li class="shortcut"><x-icon-tile name="user" tone="blue" />
                    <span><strong>{{ number_format($withAccount) }}</strong><small>Have an account</small></span></li>
            </ul>

            <form method="GET" action="{{ route('roll.index') }}" role="search" class="relative">
                <label for="roll-search" class="sr-only">Find a student on the roll</label>
                <x-icon name="search" class="pointer-events-none absolute top-1/2 left-4 -translate-y-1/2 text-muted" />
                <input id="roll-search" name="q" type="search" value="{{ $search }}" placeholder="Find a matric number or name" class="field-input pl-11">
            </form>

            @if ($entries->isEmpty())
                @if ($search !== '')
                    <x-empty-state icon="search" title="Not on the roll" text="No student on the roll matches “{{ $search }}”. A student who isn't on it can't vote in elections limited to the roll." />
                @else
                    <x-empty-state icon="users" tone="green" title="The roll is empty" text="Import the current class list to limit elections to real, current students." />
                @endif
            @else
                <ul class="roll-list">
                    @foreach ($entries as $entry)
                        <li>
                            <span class="roll-matric">{{ $entry->matric_number }}</span>
                            <span class="min-w-0 flex-1 truncate">{{ $entry->fullname ?? '—' }}</span>
                            @if ($entry->level)
                                <x-badge class="shrink-0">{{ $entry->level->label() }}</x-badge>
                            @endif
                        </li>
                    @endforeach
                </ul>

                {{ $entries->links('partials.pagination') }}
            @endif
        </div>

        <aside class="space-y-4 lg:sticky lg:top-24">
            <x-card class="space-y-4">
                <div class="flex items-center gap-3">
                    <x-icon-tile name="upload" tone="green" size="sm" />
                    <h2 class="text-base">Import the roll</h2>
                </div>

                @if ($votingOpen)
                    <x-alert type="warning">Voting is open in an election limited to the roll. Changes apply to it straight away.</x-alert>
                @endif

                <form method="POST" action="{{ route('roll.store') }}" enctype="multipart/form-data" class="space-y-4" novalidate>
                    @csrf
                    <div>
                        <label for="roll" class="field-label">CSV file</label>
                        <input id="roll" name="roll" type="file" accept=".csv,text/csv,text/plain" required class="field-input field-file"
                            @error('roll') aria-invalid="true" aria-describedby="roll-error" @enderror>
                        @error('roll')
                            <p id="roll-error" class="field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <fieldset class="space-y-2">
                        <legend class="field-label">This file is</legend>
                        <label class="flex items-start gap-3">
                            <input type="radio" name="mode" value="replace" class="mt-1 size-4 accent-[var(--primary)]" @checked(old('mode', 'replace') === 'replace')>
                            <span><strong class="text-sm">The whole roll</strong><span class="block text-sm text-muted">Students not in the file are removed (graduates, withdrawals).</span></span>
                        </label>
                        <label class="flex items-start gap-3">
                            <input type="radio" name="mode" value="add" class="mt-1 size-4 accent-[var(--primary)]" @checked(old('mode') === 'add')>
                            <span><strong class="text-sm">Extra students</strong><span class="block text-sm text-muted">Added to the roll; nobody is removed.</span></span>
                        </label>
                    </fieldset>

                    <x-button icon="upload" class="w-full">Import</x-button>
                </form>

                <div class="text-sm text-muted">
                    <p>One student per row, with a column of matric numbers. Name and level columns are optional; when a level is given, it decides which elections the student can vote in.</p>
                    <pre class="roll-sample">Matric number,Name,Level
F/ND/24/1234567,Ada Obi,ND1
F/HD/23/7654321,Tunde Bello,HND1</pre>
                </div>

                @if ($lastImport)
                    <p class="border-t border-border pt-3 text-xs text-muted">
                        Last import {{ $lastImport->created_at->timezone($zone)->format('j M Y, g:i a') }}@if ($lastImport->user) by {{ $lastImport->user->fullname }}@endif.
                    </p>
                @endif
            </x-card>
        </aside>
    </div>
</x-layouts.app>
