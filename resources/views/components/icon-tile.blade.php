{{-- An icon on a softly tinted square. Tones: green, yellow, blue, violet, red, neutral. --}}
@props(['name', 'tone' => 'green', 'size' => 'md'])

<span {{ $attributes->class(['icon-tile', 'icon-tile-'.$tone, 'icon-tile-'.$size]) }} aria-hidden="true">
    <x-icon :name="$name" />
</span>
