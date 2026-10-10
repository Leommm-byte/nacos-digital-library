<form method="POST" action="{{ $action }}" class="setup-form" enctype="multipart/form-data" novalidate>
    @csrf
    @if ($method !== 'POST')
        @method($method)
    @endif
    @php
        $fieldErrors = $errors->getBag($bag);
        $photo = $candidate?->photoUrl($election);
    @endphp
    {{-- Shown on the ballot and the results. elections.js previews the choice. --}}
    <div class="photo-picker" data-photo-picker>
        <span class="photo-picker-preview" data-photo-preview>
            <x-candidate-avatar :name="$candidate?->name ?? '?'" :photo="$photo" size="xl" />
        </span>
        <div class="min-w-0 space-y-2">
            <p class="field-label" id="{{ $prefix }}-photo-label">Photo <span class="font-normal text-muted">(optional)</span></p>
            <p class="text-xs text-muted">A clear photo of the face. It's cropped to a square and shown on the ballot and the results.</p>
            <div class="flex flex-wrap items-center gap-3">
                <label class="btn btn-secondary btn-sm photo-picker-button">
                    <input id="{{ $prefix }}-photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" aria-labelledby="{{ $prefix }}-photo-label"
                        @if ($fieldErrors->has('photo')) aria-invalid="true" aria-describedby="{{ $prefix }}-photo-error" @endif data-photo-input>
                    <x-icon name="camera" /> {{ $photo ? 'Change photo' : 'Choose photo' }}
                </label>
                @if ($photo)
                    <label class="inline-flex items-center gap-2 text-sm">
                        <input type="checkbox" name="remove_photo" value="1" class="size-4 accent-[var(--primary)]" data-photo-remove> Remove photo
                    </label>
                @endif
            </div>
            @if ($fieldErrors->has('photo'))
                <p id="{{ $prefix }}-photo-error" class="field-error">{{ $fieldErrors->first('photo') }}</p>
            @endif
        </div>
    </div>
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
