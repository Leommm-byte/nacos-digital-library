<x-layouts.app title="Know your executives" description="The current NACOS YabaTech executives: who they are and what they do.">
    <x-page-header title="Know your executives" subtitle="The students leading NACOS YabaTech this session." :back="route('elections.index')" back-label="Elections">
        @can('access-admin')
            <x-slot:actions>
                <x-button href="{{ route('admin.executives.index') }}" variant="secondary" icon="pencil">Edit</x-button>
            </x-slot:actions>
        @endcan
    </x-page-header>

    @if ($executives->isEmpty())
        <x-empty-state icon="users" tone="green" title="Executives coming soon" text="The current executives will appear here once they're announced." />
    @else
        <ul class="executive-grid stagger">
            @foreach ($executives as $executive)
                <li class="executive-card">
                    <x-candidate-avatar :name="$executive->name" :photo="$executive->photoUrl()" size="xl" class="executive-photo" />
                    <p class="executive-position">{{ $executive->position }}</p>
                    <h2 class="executive-name">{{ $executive->name }}</h2>
                    @if ($executive->details)
                        <p class="executive-details">{{ $executive->details }}</p>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</x-layouts.app>
