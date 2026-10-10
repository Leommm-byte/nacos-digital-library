{{-- Who sits a paper: levels, programmes and courses; none ticked is everyone. --}}
@php
    $exam ??= null;
    $useOld ??= false;
    $pick = fn (string $key) => (array) ($useOld ? old($key, $exam?->{$key} ?? []) : ($exam?->{$key} ?? []));
@endphp

<fieldset class="space-y-3">
    <legend class="field-label">Who sits it</legend>
    <div class="chip-options">
        @foreach (\App\Enums\Level::cases() as $level)
            <label class="chip-option">
                <input type="checkbox" name="levels[]" value="{{ $level->value }}" class="sr-only" @checked(in_array($level->value, $pick('levels'), true))>
                <span>{{ $level->label() }}</span>
            </label>
        @endforeach
    </div>
    <div class="chip-options">
        @foreach (\App\Enums\Programme::cases() as $programme)
            <label class="chip-option">
                <input type="checkbox" name="programmes[]" value="{{ $programme->value }}" class="sr-only" @checked(in_array($programme->value, $pick('programmes'), true))>
                <span>{{ $programme->label() }}</span>
            </label>
        @endforeach
        @foreach (\App\Support\Classes\Arms::all() as $key => $arm)
            <label class="chip-option">
                <input type="checkbox" name="arms[]" value="{{ $key }}" class="sr-only" @checked(in_array($key, $pick('arms'), true))>
                <span>{{ $arm['short'] }}</span>
            </label>
        @endforeach
    </div>
    <p class="text-xs text-muted">None ticked means everyone. Tick ND2 for every ND2 class, or HND1 and SWD for HND1 SWD only.</p>
</fieldset>
