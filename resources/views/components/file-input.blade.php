{{--
    A file field that copes with long names on phones and lets you take a
    choice back:
    <x-file-input name="cover" label="Cover" accept="image/*" icon="camera" />
    files.js swaps the browser's own control for a button and, once a file is
    chosen, a row with its name (shortened to fit), size and a remove button
    (and a thumbnail for a photo).
    Without JavaScript the browser's control is shown, kept inside the page.
    Extra attributes (required, data-*, aria-labelledby) go on the input.
--}}
@props(['name', 'label' => null, 'hint' => null, 'bag' => 'default', 'icon' => 'file-up', 'button' => 'Choose file'])

@php
    $id = $attributes->get('id', $name);
    $error = $errors->getBag($bag)->first($name);
    $describedBy = collect([$attributes->get('aria-describedby'), $hint ? $id.'-hint' : null, $error ? $id.'-error' : null])->filter()->implode(' ');
@endphp

<div>
    @if ($label)
        <label for="{{ $id }}" class="field-label">{{ $label }}</label>
    @endif
    <div class="file-input" data-file-input>
        <input
            id="{{ $id }}"
            name="{{ $name }}"
            type="file"
            @if ($error) aria-invalid="true" @endif
            @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
            {{ $attributes->except(['id', 'aria-describedby'])->class(['field-input', 'field-file', 'file-input-native']) }}
        >
        {{-- Opens the (visually hidden) input, which keeps keyboard focus. --}}
        <span class="btn btn-secondary btn-sm file-input-pick" data-file-input-pick aria-hidden="true">
            <x-icon :name="$icon" /> {{ $button }}
        </span>
        <span class="file-input-empty" data-file-input-pick aria-hidden="true">No file chosen</span>
        <div class="file-input-chosen" data-file-input-chosen hidden>
            <x-icon :name="$icon" class="shrink-0 text-primary" data-file-input-icon />
            <img class="file-input-thumb" alt="" data-file-input-thumb hidden>
            <span class="file-input-name" data-file-input-name></span>
            <span class="file-input-size" data-file-input-size></span>
            <button type="button" class="icon-btn file-input-clear" data-file-input-clear aria-label="Remove the chosen file">
                <x-icon name="x" />
            </button>
        </div>
    </div>
    @if ($hint)
        <p id="{{ $id }}-hint" class="mt-1.5 text-sm text-muted">{{ $hint }}</p>
    @endif
    @if ($error)
        <p id="{{ $id }}-error" class="field-error">{{ $error }}</p>
    @endif
    {{ $slot ?? '' }}
</div>
