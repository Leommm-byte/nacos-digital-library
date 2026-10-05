{{-- Types: info, success, warning, error. Errors are announced immediately. --}}
@props(['type' => 'info'])

@php
    $icon = [
        'info' => 'info',
        'success' => 'circle-check',
        'warning' => 'triangle-alert',
        'error' => 'circle-alert',
    ][$type] ?? 'info';
@endphp

<div {{ $attributes->class(['alert', 'alert-'.$type]) }} role="{{ $type === 'error' ? 'alert' : 'status' }}">
    <x-icon :name="$icon" />
    <div class="min-w-0 flex-1">{{ $slot }}</div>
</div>
