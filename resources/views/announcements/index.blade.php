@php($zone = config('app.display_timezone'))

<x-layouts.app title="Announcements">
    <x-page-header title="Announcements" subtitle="News and notices from NACOS YabaTech, and from your class.">
        <x-slot:actions>
            @can('manage-announcements')
                <x-button href="{{ route('announcements.manage') }}" variant="secondary" icon="settings">Manage</x-button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    @if ($announcements->isEmpty())
        <x-empty-state icon="bell" title="No announcements right now" text="News from NACOS and your class will appear here." />
    @else
        <ol class="announcement-feed stagger">
            @foreach ($announcements as $announcement)
                <li>
                    <article class="announcement-card">
                        <p class="announcement-meta">
                            <time datetime="{{ $announcement->date()->toIso8601String() }}">{{ $announcement->date()->timezone($zone)->format('l, j F Y') }}</time>
                            @unless ($announcement->isForEveryone())
                                <x-badge variant="accent">{{ $announcement->audience() }}</x-badge>
                            @endunless
                        </p>
                        <h2 class="mt-1 text-lg"><a href="{{ route('announcements.show', $announcement) }}" class="stretched-link">{{ $announcement->title }}</a></h2>
                        <p class="mt-2 text-muted">{{ $announcement->excerpt() }}</p>
                        @if ($announcement->isLong())
                            <span class="announcement-more mt-3">Read more <x-icon name="arrow-right" /></span>
                        @endif
                    </article>
                </li>
            @endforeach
        </ol>

        {{ $announcements->links('partials.pagination') }}
    @endif
</x-layouts.app>
