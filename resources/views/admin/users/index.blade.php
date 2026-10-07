@php
    $roles = ['' => 'Any role'] + collect(\App\Enums\Role::cases())->mapWithKeys(fn ($r) => [$r->value => $r->label()])->all();
    $statuses = ['' => 'Any status', 'active' => 'Active', 'suspended' => 'Suspended'];
    $levels = ['' => 'Any level'] + collect(\App\Enums\Level::cases())->mapWithKeys(fn ($l) => [$l->value => $l->label()])->all();
    $programmes = ['' => 'Any programme'] + collect(\App\Enums\Programme::cases())->mapWithKeys(fn ($p) => [$p->value => $p->label()])->all();
@endphp

<x-layouts.admin title="Users">
    <x-page-header title="Users" :subtitle="number_format($users->total()).' '.\Illuminate\Support\Str::plural('account', $users->total()).($search !== '' || array_filter($filters) ? ' found' : '')">
        <x-slot:actions>
            <x-button href="{{ route('admin.accounts.index') }}" icon="user-plus">Create accounts</x-button>
        </x-slot:actions>
    </x-page-header>

    <form method="GET" action="{{ route('admin.users.index') }}" class="filter-bar mb-6" data-autosubmit>
        <div class="filter-search relative">
            <label for="user-search" class="field-label">Search</label>
            <input id="user-search" name="q" type="search" value="{{ $search }}" placeholder="Name, matric number or email" class="field-input">
        </div>
        <x-select name="role" label="Role" :options="$roles" :value="$filters['role'] ?? ''" />
        <x-select name="status" label="Status" :options="$statuses" :value="$filters['status'] ?? ''" />
        <x-select name="level" label="Level" :options="$levels" :value="$filters['level'] ?? ''" />
        <x-select name="programme" label="Programme" :options="$programmes" :value="$filters['programme'] ?? ''" />
        <div class="filter-actions"><x-button variant="secondary" icon="search">Filter</x-button></div>
    </form>

    @if ($users->isEmpty())
        <x-empty-state icon="users" title="No accounts found" text="Try another name or matric number, or clear the filters.">
            <x-button href="{{ route('admin.users.index') }}" variant="secondary">Clear filters</x-button>
        </x-empty-state>
    @else
        <div class="table-scroll">
            <table class="admin-table">
                <thead>
                    <tr><th>Name</th><th>Matric number</th><th>Class</th><th>Role</th><th>Status</th></tr>
                </thead>
                <tbody>
                    @foreach ($users as $user)
                        <tr>
                            <td>
                                <a href="{{ route('admin.users.show', $user) }}" class="user-cell">
                                    <span class="user-initial" aria-hidden="true">{{ mb_strtoupper(mb_substr($user->fullname, 0, 1)) }}</span>
                                    <span class="min-w-0">
                                        <span class="block truncate font-semibold">{{ $user->fullname }}</span>
                                        <span class="block truncate text-xs text-muted">{{ $user->email ?? 'No email' }}</span>
                                    </span>
                                </a>
                            </td>
                            <td class="whitespace-nowrap">{{ $user->matric_number }}</td>
                            <td class="whitespace-nowrap">{{ $user->level->label() }} · {{ $user->programme->label() }}</td>
                            <td><x-badge :variant="$user->role === \App\Enums\Role::Student ? 'neutral' : 'primary'">{{ $user->role->label() }}</x-badge></td>
                            <td>
                                @if ($user->isSuspended())
                                    <x-badge variant="danger">Suspended</x-badge>
                                @else
                                    <span class="text-sm text-muted">Active</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{ $users->links('partials.pagination') }}
    @endif
</x-layouts.admin>
