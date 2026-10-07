@php
    $zone = config('app.display_timezone');
@endphp

<x-layouts.app title="Manage elections">
    <x-page-header title="Manage elections" subtitle="Set up an election, choose who can vote, then open voting for a set time." :back="route('elections.index')" back-label="Elections">
        <x-slot:actions>
            <x-button href="{{ route('elections.manage.create') }}" icon="plus">New election</x-button>
        </x-slot:actions>
    </x-page-header>

    @if ($elections->isEmpty())
        <x-empty-state icon="vote" tone="green" title="No elections yet" text="Create one, add its positions and candidates, then launch it when you're ready.">
            <x-button href="{{ route('elections.manage.create') }}" icon="plus">New election</x-button>
        </x-empty-state>
    @else
        <ul class="upload-list">
            @foreach ($elections as $election)
                @php
                    [$state, $variant, $tone] = match ($election->status) {
                        \App\Enums\ElectionStatus::Draft => ['Draft', 'neutral', 'neutral'],
                        \App\Enums\ElectionStatus::Open => [$election->hasEnded() ? 'Counting' : 'Voting open', 'primary', 'green'],
                        \App\Enums\ElectionStatus::Closed => ['Closed', 'neutral', 'yellow'],
                    };
                @endphp
                <li>
                    <a href="{{ route('elections.manage.show', $election) }}" class="upload-row">
                        <x-icon-tile name="vote" :tone="$tone" size="sm" />
                        <span class="min-w-0 flex-1">
                            <span class="upload-row-title">{{ $election->title }}</span>
                            <span class="upload-row-meta">
                                {{ $election->positions_count }} {{ \Illuminate\Support\Str::plural('position', $election->positions_count) }}
                                @if ($election->isDraft())
                                    · {{ $election->eligibilitySummary() }}
                                @elseif ($election->status === \App\Enums\ElectionStatus::Open)
                                    · {{ number_format($election->voters_count) }} voted · closes {{ $election->ends_at?->timezone($zone)->format('j M, g:i a') }}
                                @else
                                    · {{ number_format($election->voters_count) }} voted · closed {{ ($election->closed_at ?? $election->ends_at)?->timezone($zone)->format('j M Y') }}
                                @endif
                            </span>
                        </span>
                        <x-badge :variant="$variant" class="shrink-0">{{ $state }}</x-badge>
                        <x-icon name="chevron-right" class="shrink-0 text-muted" />
                    </a>
                </li>
            @endforeach
        </ul>

        {{ $elections->links('partials.pagination') }}
    @endif
</x-layouts.app>
