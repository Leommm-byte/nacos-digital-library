{{-- Closed elections with their turnout. Expects $past, $results and $show (election => URL). --}}
@php
    $zone = config('app.display_timezone');
    $percent = fn ($value) => \Illuminate\Support\Number::format((float) $value, maxPrecision: 1);
@endphp
<ul class="upload-list">
    @foreach ($past as $election)
        @php($result = $results[$election->id] ?? null)
        <li>
            <a href="{{ $show($election) }}" class="upload-row">
                <x-icon-tile name="vote" tone="neutral" size="sm" />
                <span class="min-w-0 flex-1">
                    <span class="upload-row-title">{{ $election->title }}</span>
                    <span class="upload-row-meta">
                        Closed {{ ($election->closed_at ?? $election->ends_at)?->timezone($zone)->format('j M Y') }}
                        @if ($result) · {{ number_format($result['ballots']) }} voted ({{ $percent($result['turnout']) }}%) @endif
                    </span>
                </span>
                <x-badge class="shrink-0">Final</x-badge>
                <x-icon name="chevron-right" class="shrink-0 text-muted" />
            </a>
        </li>
    @endforeach
</ul>
