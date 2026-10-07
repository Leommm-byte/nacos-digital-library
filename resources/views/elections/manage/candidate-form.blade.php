<form method="POST" action="{{ $action }}" class="setup-form" novalidate>
    @csrf
    @if ($method !== 'POST')
        @method($method)
    @endif
    @php($fieldErrors = $errors->getBag($bag))
    <div class="grid gap-4 sm:grid-cols-2">
        <x-field name="name" :id="$prefix.'-name'" label="Full name" :bag="$bag" required maxlength="150" :value="$candidate?->name" :old="$fieldErrors->any()" autocomplete="off" />
        <x-field name="matric_number" :id="$prefix.'-matric'" label="Matric number (optional)" :bag="$bag" maxlength="32" :value="$candidate?->matric_number" :old="$fieldErrors->any()" autocomplete="off" placeholder="F/ND/24/1234567" />
    </div>
    <div>
        <label for="{{ $prefix }}-manifesto" class="field-label">Manifesto <span class="font-normal text-muted">(optional)</span></label>
        <textarea id="{{ $prefix }}-manifesto" name="manifesto" rows="3" maxlength="1000" class="field-input field-textarea"
            @if ($fieldErrors->has('manifesto')) aria-invalid="true" aria-describedby="{{ $prefix }}-manifesto-error" @endif
            placeholder="A short statement voters see on the ballot.">{{ $fieldErrors->any() ? old('manifesto') : $candidate?->manifesto }}</textarea>
        @if ($fieldErrors->has('manifesto'))
            <p id="{{ $prefix }}-manifesto-error" class="field-error">{{ $fieldErrors->first('manifesto') }}</p>
        @endif
    </div>
    <x-button size="sm" variant="secondary">{{ $submit }}</x-button>
</form>
