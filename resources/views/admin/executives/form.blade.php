{{-- Add or edit an executive. elections.js previews the chosen photo. --}}
@php
    $fieldErrors = $errors->getBag($bag);
    $photo = $executive?->photoUrl();
@endphp
<form method="POST" action="{{ $action }}" class="space-y-4" enctype="multipart/form-data" novalidate>
    @csrf
    @if ($method !== 'POST')
        @method($method)
    @endif
    <div class="photo-picker" data-photo-picker>
        <span class="photo-picker-preview" data-photo-preview>
            <x-candidate-avatar :name="$executive?->name ?? '?'" :photo="$photo" size="xl" />
        </span>
        <div class="min-w-0 space-y-2">
            <p class="field-label" id="{{ $prefix }}-photo-label">Photo <span class="font-normal text-muted">(optional)</span></p>
            <p class="text-xs text-muted">Cropped to a square.</p>
            <div class="flex flex-wrap items-center gap-3">
                <label class="btn btn-secondary btn-sm photo-picker-button">
                    <input id="{{ $prefix }}-photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" aria-labelledby="{{ $prefix }}-photo-label"
                        @if ($fieldErrors->has('photo')) aria-invalid="true" aria-describedby="{{ $prefix }}-photo-error" @endif data-photo-input>
                    <x-icon name="camera" /> {{ $photo ? 'Change photo' : 'Choose photo' }}
                </label>
                <button type="button" class="btn btn-ghost btn-sm" data-photo-clear hidden><x-icon name="x" /> Don't use this photo</button>
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
        <x-field name="name" :id="$prefix.'-name'" label="Full name" :bag="$bag" required maxlength="150" :value="$executive?->name" :old="$fieldErrors->any()" autocomplete="off" />
        <x-field name="position" :id="$prefix.'-position'" label="Position" :bag="$bag" required maxlength="100" :value="$executive?->position" :old="$fieldErrors->any()" autocomplete="off" placeholder="President" />
    </div>
    <x-field name="details" :id="$prefix.'-details'" label="Details (optional)" :bag="$bag" maxlength="200" :value="$executive?->details" :old="$fieldErrors->any()" placeholder="HND2 SWD · president@nacosyabatech.org" hint="Their class, or how to reach them." />
    <x-button size="sm" :icon="$executive ? 'circle-check' : 'plus'">{{ $executive ? 'Save' : 'Add executive' }}</x-button>
</form>
