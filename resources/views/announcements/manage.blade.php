<x-layouts.app title="Manage announcements">
    <x-page-header title="Manage announcements" subtitle="Students see up to three live announcements on their home page." :back="route('announcements.index')" back-label="Announcements">
        <x-slot:actions>
            <x-button href="{{ route('announcements.create') }}" icon="bell">New announcement</x-button>
        </x-slot:actions>
    </x-page-header>

    @if ($announcements->isEmpty())
        <x-empty-state icon="bell" title="No announcements yet" text="Post news, deadlines or events for students.">
            <x-button href="{{ route('announcements.create') }}" icon="bell">New announcement</x-button>
        </x-empty-state>
    @else
        <ul class="upload-list">
            @foreach ($announcements as $announcement)
                @php
                    $now = now();
                    [$state, $variant] = match (true) {
                        ! $announcement->is_published => ['Draft', 'neutral'],
                        $announcement->starts_at && $announcement->starts_at->isAfter($now) => ['Scheduled', 'accent'],
                        $announcement->ends_at && $announcement->ends_at->isBefore($now) => ['Ended', 'neutral'],
                        default => ['Live', 'primary'],
                    };
                    $zone = config('app.display_timezone');
                @endphp
                <li>
                    <a href="{{ route('announcements.edit', $announcement) }}" class="upload-row">
                        <x-icon-tile name="bell" :tone="$state === 'Live' ? 'green' : 'neutral'" size="sm" />
                        <span class="min-w-0 flex-1">
                            <span class="upload-row-title">{{ $announcement->title }}</span>
                            <span class="upload-row-meta">
                                {{ $announcement->starts_at ? 'From '.$announcement->starts_at->timezone($zone)->format('j M Y') : 'From posting' }}
                                · {{ $announcement->ends_at ? 'until '.$announcement->ends_at->timezone($zone)->format('j M Y') : 'no end date' }}
                                @if ($announcement->author) · by {{ $announcement->author->fullname }} @endif
                            </span>
                        </span>
                        <x-badge :variant="$variant" class="shrink-0">{{ $state }}</x-badge>
                        <x-icon name="chevron-right" class="shrink-0 text-muted" />
                    </a>
                </li>
            @endforeach
        </ul>

        {{ $announcements->links('partials.pagination') }}
    @endif
</x-layouts.app>
