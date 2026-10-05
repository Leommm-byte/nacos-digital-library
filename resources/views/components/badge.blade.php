{{-- Variants: neutral, primary, accent, danger. --}}
@props(['variant' => 'neutral'])

<span {{ $attributes->class(['badge', 'badge-'.$variant => $variant !== 'neutral']) }}>{{ $slot }}</span>
