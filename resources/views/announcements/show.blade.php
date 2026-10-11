@php
    $zone = config('app.display_timezone');
    $live = $announcement->isLive();
@endphp

<x-layouts.app :title="$announcement->title" :description="$announcement->excerpt(150)">
    <x-page-header :title="$announcement->title" :back="route('announcements.index')" back-label="Announcements">
        <x-slot:actions>
            @can('update', $announcement)
                <x-button href="{{ route('announcements.edit', $announcement) }}" variant="secondary" icon="pencil">Edit</x-button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <article class="announcement-page">
        <p class="announcement-meta">
            <time datetime="{{ $announcement->date()->toIso8601String() }}">{{ $announcement->date()->timezone($zone)->format('l, j F Y') }}</time>
            <x-badge :variant="$announcement->isForEveryone() ? 'neutral' : 'accent'">{{ $announcement->isForEveryone() ? 'For everyone' : 'For '.$announcement->audience() }}</x-badge>
            @unless ($live)
                <x-badge variant="neutral">{{ ! $announcement->is_published ? 'Draft' : ($announcement->ends_at?->isPast() ? 'Ended' : 'Scheduled') }}</x-badge>
            @endunless
        </p>

        @unless ($live)
            <x-alert type="info" class="mt-4">
                @if (! $announcement->is_published)
                    This is a draft. Only you and admins can see it until it's published.
                @elseif ($announcement->ends_at?->isPast())
                    This announcement has ended, so students no longer see it.
                @else
                    Students see this from {{ $announcement->starts_at?->timezone($zone)->format('l, j F') }}.
                @endif
            </x-alert>
        @endunless

        <div class="prose mt-6">{!! $announcement->html() !!}</div>

        @if ($announcement->author)
            <p class="mt-8 border-t border-border pt-4 text-sm text-muted">Posted by {{ $announcement->author->fullname }}</p>
        @endif
    </article>
</x-layouts.app>
