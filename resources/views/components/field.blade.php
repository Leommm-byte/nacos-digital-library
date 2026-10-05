{{--
    Labelled input with its validation error:
    <x-field name="email" label="Email" type="email" autocomplete="email" />
    Password fields get a show/hide button. `bag` picks a named error bag
    when a page has several forms.
--}}
@props(['name', 'label', 'type' => 'text', 'hint' => null, 'bag' => 'default'])

@php
    $id = $attributes->get('id', $name);
    $error = $errors->getBag($bag)->first($name);
    $describedBy = collect([$attributes->get('aria-describedby'), $hint ? $id.'-hint' : null, $error ? $id.'-error' : null])->filter()->implode(' ');
@endphp

<div>
    <label for="{{ $id }}" class="field-label">{{ $label }}</label>
    <div @class(['relative' => $type === 'password'])>
        <input
            id="{{ $id }}"
            name="{{ $name }}"
            type="{{ $type }}"
            @if ($type !== 'password') value="{{ old($name, $attributes->get('value')) }}" @endif
            @if ($error) aria-invalid="true" @endif
            @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
            {{ $attributes->except(['id', 'value', 'aria-describedby'])->class(['field-input', 'pr-12' => $type === 'password']) }}
        >
        @if ($type === 'password')
            <button type="button" class="password-toggle" data-password-toggle="{{ $id }}" aria-controls="{{ $id }}" aria-pressed="false" aria-label="Show password">
                <x-icon name="eye" class="password-toggle-show" />
                <x-icon name="eye-off" class="password-toggle-hide" />
            </button>
        @endif
    </div>
    @if ($hint)
        <p id="{{ $id }}-hint" class="mt-1.5 text-sm text-muted">{{ $hint }}</p>
    @endif
    @if ($error)
        <p id="{{ $id }}-error" class="field-error">{{ $error }}</p>
    @endif
    {{ $slot ?? '' }}
</div>
