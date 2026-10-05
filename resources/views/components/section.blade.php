{{--
    A titled block of a page, with an optional description and a link or
    button on the right ("See all").
--}}
@props(['title' => null, 'description' => null, 'icon' => null, 'tone' => 'green'])

<section {{ $attributes->class(['page-section']) }}>
    @if ($title || isset($action))
        <div class="page-section-head">
            <div class="flex min-w-0 items-center gap-3">
                @if ($icon)
                    <x-icon-tile :name="$icon" :tone="$tone" size="sm" />
                @endif
                <div class="min-w-0">
                    @if ($title)
                        <h2 class="page-section-title">{{ $title }}</h2>
                    @endif
                    @if ($description)
                        <p class="page-section-description">{{ $description }}</p>
                    @endif
                </div>
            </div>
            @isset($action)
                <div class="shrink-0">{{ $action }}</div>
            @endisset
        </div>
    @endif
    {{ $slot }}
</section>
