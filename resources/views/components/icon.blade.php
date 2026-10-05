{{--
    <x-icon name="book-open" />                   decorative (hidden from screen readers)
    <x-icon name="search" label="Search" />       meaningful on its own
--}}
@props(['name', 'label' => null])

<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" {{ $attributes->class(['icon']) }} @if ($label) role="img" aria-label="{{ $label }}" @else aria-hidden="true" focusable="false" @endif>{!! \App\Support\Icons::body($name) !!}</svg>
