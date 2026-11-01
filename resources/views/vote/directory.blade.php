<x-layouts.app title="Department elections" description="Departments at Yaba College of Technology running their elections with NACOS YabaTech: secret ballot, live results.">
    <x-page-header title="Department elections" subtitle="Departments that run their elections with NACOS YabaTech: registration from the nominal roll, a secret ballot and live results.">
        <x-slot:actions>
            <x-button href="{{ route('vote.apply') }}" icon="building-2">Ask for your department</x-button>
        </x-slot:actions>
    </x-page-header>

    @if ($departments->isEmpty())
        <x-empty-state icon="building-2" tone="green" title="No departments yet" text="Want your department's elections run here? Ask for a space.">
            <x-button href="{{ route('vote.apply') }}" icon="building-2">Ask for your department</x-button>
        </x-empty-state>
    @else
        <ul class="upload-list">
            @foreach ($departments as $department)
                <li>
                    <a href="{{ route('vote.home', $department) }}" class="upload-row">
                        <x-icon-tile name="vote" tone="green" size="sm" />
                        <span class="min-w-0 flex-1">
                            <span class="upload-row-title">{{ $department->name }}</span>
                            <span class="upload-row-meta">{{ preg_replace('#^https?://#', '', route('vote.home', $department)) }}</span>
                        </span>
                        <x-icon name="chevron-right" class="shrink-0 text-muted" />
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</x-layouts.app>
