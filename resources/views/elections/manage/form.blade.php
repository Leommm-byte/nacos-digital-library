@php
    $editing = $election->exists;
    $levels = old('levels', $election->levels ?? []);
    $programmes = old('programmes', $election->programmes ?? []);
    $yearOptions = ['' => 'Any year'] + $years;
    $rollOnly = $errors->any() ? (bool) old('roll_only') : $election->roll_only;
@endphp

<x-layouts.admin :title="$editing ? 'Edit election' : 'New election'">
    <x-page-header :title="$editing ? 'Edit election' : 'New election'" subtitle="Positions and candidates come next. Nothing is public until you launch it."
        :back="$editing ? route('elections.manage.show', $election) : route('elections.manage')" :back-label="$editing ? $election->title : 'Manage elections'" />

    <form method="POST" action="{{ $editing ? route('elections.manage.update', $election) : route('elections.manage.store') }}" class="max-w-2xl space-y-6" novalidate>
        @csrf
        @if ($editing)
            @method('PUT')
        @endif

        <x-card class="space-y-4">
            <x-field name="title" label="Title" required maxlength="200" :value="$election->title" placeholder="Executive Council Election 2026" />
            <div>
                <label for="description" class="field-label">Description <span class="font-normal text-muted">(optional)</span></label>
                <textarea id="description" name="description" rows="3" maxlength="2000" class="field-input field-textarea"
                    @error('description') aria-invalid="true" aria-describedby="description-error" @enderror
                    placeholder="What the election is for, and anything voters should know.">{{ old('description', $election->description) }}</textarea>
                @error('description')
                    <p id="description-error" class="field-error">{{ $message }}</p>
                @enderror
            </div>
        </x-card>

        <x-card class="space-y-5">
            <div class="flex items-center gap-3">
                <x-icon-tile name="users" tone="blue" size="sm" />
                <div>
                    <h2 class="text-base">Who can vote</h2>
                    <p class="text-sm text-muted">Every active account, unless you limit it below.</p>
                </div>
            </div>

            <label class="roll-toggle">
                <input type="checkbox" name="roll_only" value="1" class="mt-1 size-4 shrink-0 accent-[var(--primary)]" @checked($rollOnly)>
                <span>
                    <strong>Only students on the nominal roll</strong>
                    <span class="block text-sm text-muted">
                        Graduates and made-up matric numbers can't vote. {{ number_format($rollCount) }} {{ \Illuminate\Support\Str::plural('student', $rollCount) }} on the roll now.
                        <a href="{{ route('roll.index') }}" class="link">{{ $rollCount ? 'See the roll' : 'Import the roll' }}</a>
                    </span>
                </span>
            </label>

            <fieldset>
                <legend class="field-label">Programmes</legend>
                <div class="chip-options">
                    @foreach (\App\Enums\Programme::cases() as $programme)
                        <label class="chip-option">
                            <input type="checkbox" name="programmes[]" value="{{ $programme->value }}" class="sr-only" @checked(in_array($programme->value, (array) $programmes, true))>
                            <span>{{ $programme->label() }}</span>
                        </label>
                    @endforeach
                </div>
                <p class="mt-1.5 text-sm text-muted">None ticked means every programme. For a class election, tick its programme and level.</p>
                @error('programmes')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </fieldset>

            <fieldset>
                <legend class="field-label">Levels</legend>
                <div class="chip-options">
                    @foreach (\App\Enums\Level::cases() as $level)
                        <label class="chip-option">
                            <input type="checkbox" name="levels[]" value="{{ $level->value }}" class="sr-only" @checked(in_array($level->value, (array) $levels, true))>
                            <span>{{ $level->label() }}</span>
                        </label>
                    @endforeach
                </div>
                <p class="mt-1.5 text-sm text-muted">None ticked means all levels. The class on the nominal roll counts over the one students chose at signup.</p>
                @error('levels')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </fieldset>

            <div>
                <p class="field-label">Matric entry years</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-select name="entry_year_from" label="From" :options="$yearOptions" :value="$election->entry_year_from" />
                    <x-select name="entry_year_to" label="To" :options="$yearOptions" :value="$election->entry_year_to" />
                </div>
                <p class="mt-1.5 text-sm text-muted">The year in the matric number: F/ND/<strong>24</strong>/1234567 entered in 2024. Both years are included.</p>
            </div>
        </x-card>

        <div class="flex flex-wrap items-center gap-3">
            <x-button icon="circle-check">{{ $editing ? 'Save changes' : 'Create election' }}</x-button>
            <x-button href="{{ $editing ? route('elections.manage.show', $election) : route('elections.manage') }}" variant="ghost">Cancel</x-button>
        </div>
    </form>
</x-layouts.admin>
