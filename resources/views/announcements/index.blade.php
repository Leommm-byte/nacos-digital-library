<x-layouts.app title="Announcements">
    <x-page-header title="Announcements" subtitle="News and notices from NACOS YabaTech.">
        <x-slot:actions>
            @can('manage-announcements')
                <x-button href="{{ route('announcements.manage') }}" variant="secondary" icon="settings">Manage</x-button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    @if ($announcements->isEmpty())
        <x-empty-state icon="bell" title="No announcements right now" text="News from NACOS will appear here." />
    @else
        <ol class="announcement-feed stagger">
            @foreach ($announcements as $announcement)
                <li>
                    <article class="card p-5 md:p-6">
                        <time class="announcement-date" datetime="{{ ($announcement->starts_at ?? $announcement->created_at)->toIso8601String() }}">
                            {{ ($announcement->starts_at ?? $announcement->created_at)->timezone(config('app.display_timezone'))->format('l, j F Y') }}
                        </time>
                        <h2 class="mt-1 text-lg">{{ $announcement->title }}</h2>
                        <p class="mt-2 whitespace-pre-line text-muted">{{ $announcement->body }}</p>
                    </article>
                </li>
            @endforeach
        </ol>

        {{ $announcements->links('partials.pagination') }}
    @endif
</x-layouts.app>
