<x-layouts.app title="Notifications">
    <x-page-header title="Notifications" :subtitle="$unread ? $unread.' unread' : 'You\'re all caught up.'">
        <x-slot:actions>
            @if ($unread)
                <form method="POST" action="{{ route('notifications.read-all') }}">
                    @csrf
                    <x-button variant="secondary" icon="circle-check">Mark all as read</x-button>
                </form>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($notifications->isEmpty())
        <x-empty-state icon="bell" title="No notifications yet" text="When a reviewer decides one of your uploads, you'll hear about it here." />
    @else
        <ul class="notification-list stagger">
            @foreach ($notifications as $notification)
                @php
                    $action = \App\Enums\ReviewAction::tryFrom((string) ($notification->data['action'] ?? ''));
                    [$icon, $tone] = match ($action) {
                        \App\Enums\ReviewAction::Approved => ['circle-check', 'green'],
                        \App\Enums\ReviewAction::ChangesRequested => ['triangle-alert', 'yellow'],
                        \App\Enums\ReviewAction::Rejected => ['x', 'red'],
                        default => ['bell', 'neutral'],
                    };
                @endphp
                <li>
                    <a href="{{ route('notifications.open', $notification->id) }}" @class(['notification', 'is-unread' => $notification->read_at === null])>
                        <x-icon-tile :name="$icon" :tone="$tone" size="sm" />
                        <span class="min-w-0 flex-1">
                            <span class="notification-title">{{ $notification->data['headline'] ?? 'Update' }}</span>
                            @if (! empty($notification->data['comment']))
                                <span class="notification-text">"{{ $notification->data['comment'] }}"</span>
                            @endif
                            <time class="notification-time" datetime="{{ $notification->created_at?->toIso8601String() }}">{{ $notification->created_at?->diffForHumans() }}</time>
                        </span>
                        @if ($notification->read_at === null)
                            <span class="notification-dot" aria-label="Unread"></span>
                        @endif
                    </a>
                </li>
            @endforeach
        </ul>

        {{ $notifications->links('partials.pagination') }}
    @endif
</x-layouts.app>
