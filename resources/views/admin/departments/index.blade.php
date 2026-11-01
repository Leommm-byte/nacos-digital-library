<x-layouts.admin title="Departments">
    <x-page-header title="Departments" subtitle="Departments you run elections for. Each gets its own space with its own admins, roll and voters, powered by NACOS.">
        <x-slot:actions>
            <x-button href="{{ route('admin.departments.create') }}" icon="plus">Add a department</x-button>
        </x-slot:actions>
    </x-page-header>

    @if ($departments->isEmpty())
        <x-empty-state icon="building-2" tone="green" title="No departments yet" text="Add a department you've agreed to run elections for. You'll give it its first admin next.">
            <x-button href="{{ route('admin.departments.create') }}" icon="plus">Add a department</x-button>
        </x-empty-state>
    @else
        <ul class="upload-list">
            @foreach ($departments as $department)
                <li>
                    <a href="{{ route('admin.departments.show', $department) }}" class="upload-row">
                        <x-icon-tile name="building-2" :tone="$department->isActive() ? 'green' : 'neutral'" size="sm" />
                        <span class="min-w-0 flex-1">
                            <span class="upload-row-title">{{ $department->name }}</span>
                            <span class="upload-row-meta">{{ number_format($department->roll_count) }} on the roll · {{ number_format($department->voters_count) }} {{ \Illuminate\Support\Str::plural('account', $department->voters_count) }} · {{ number_format($department->elections_count) }} {{ \Illuminate\Support\Str::plural('election', $department->elections_count) }}</span>
                        </span>
                        @unless ($department->isActive())
                            <x-badge class="shrink-0">Paused</x-badge>
                        @endunless
                        <x-icon name="chevron-right" class="shrink-0 text-muted" />
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</x-layouts.admin>
