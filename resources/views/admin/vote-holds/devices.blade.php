@php
    $zone = config('app.display_timezone');
    $kinds = ['device' => 'Same device', 'network' => 'Same network and browser'];
@endphp

<x-layouts.admin title="Shared devices">
    <x-page-header title="Shared devices" subtitle="Accounts that signed up, signed in or voted from the same device. Leads to check before putting accounts on hold, not proof." :back="route('admin.vote-holds.index')" back-label="Vote holds">
        <x-slot:actions>
            <x-button :href="route('admin.vote-holds.devices.export', ['kind' => $kind, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'min' => $min])" variant="secondary" size="sm" icon="download">Export CSV</x-button>
        </x-slot:actions>
    </x-page-header>

    <nav class="mb-4 flex flex-wrap gap-2" aria-label="Kind of match">
        @foreach ($kinds as $value => $label)
            <x-button :href="route('admin.vote-holds.devices', ['kind' => $value, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')])" size="sm" :variant="$kind === $value ? 'primary' : 'secondary'" :aria-current="$kind === $value ? 'page' : null">{{ $label }}</x-button>
        @endforeach
    </nav>

    <x-alert type="info" class="mb-6">
        @if ($kind === 'device')
            One browser on one phone or computer, recognised by an anonymous id it keeps. Strong evidence, but only for what happened after this was added to the site. For anything earlier, such as the primaries, use <a href="{{ route('admin.vote-holds.devices', ['kind' => 'network', 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')]) }}" class="link">Same network and browser</a>.
        @else
            The same internet address and the same browser details. Weaker: campus Wi-Fi gives many students one address, and many phones report identical browser details, so big groups are often just classmates. Small groups that signed up minutes apart are the ones to look at.
        @endif
    </x-alert>

    <form method="GET" action="{{ route('admin.vote-holds.devices') }}" class="filter-bar mb-6">
        <input type="hidden" name="kind" value="{{ $kind }}">
        <x-field name="from" label="From" type="date" :value="$from->format('Y-m-d')" />
        <x-field name="to" label="To" type="date" :value="$to->format('Y-m-d')" />
        <x-field name="min" label="At least this many accounts" type="number" min="2" max="50" :value="$min" />
        <div class="filter-actions"><x-button variant="secondary" icon="search">Show</x-button></div>
    </form>

    @if ($groups->isEmpty())
        <x-empty-state icon="smartphone" title="Nothing found" text="No device was used by that many accounts in these dates." />
    @else
        <form method="POST" action="{{ route('admin.vote-holds.store-many') }}" data-confirm="Put these accounts on hold? They can't vote until an admin releases them.">
            @csrf
            <input type="hidden" name="kind" value="{{ $kind }}">
            <input type="hidden" name="from" value="{{ $from->format('Y-m-d') }}">
            <input type="hidden" name="to" value="{{ $to->format('Y-m-d') }}">
            <div class="space-y-6">
                @foreach ($groups as $group)
                    <x-card>
                        @php($unheld = $group['accounts']->filter(fn ($account) => $account['user']->vote_held_at === null)->count())
                        <div class="mb-3 flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h2 id="device-{{ $loop->index }}" class="text-base">{{ $group['label'] }}</h2>
                                <p class="text-sm text-muted">{{ $group['accounts']->count() }} accounts · {{ $group['registered'] }} signed up here · {{ $group['voted'] }} voted here</p>
                            </div>
                            @if ($unheld > 0)
                                <x-button name="group" value="{{ $group['key'] }}" size="sm" variant="secondary" icon="user-lock">Put all {{ $unheld }} on hold</x-button>
                            @endif
                        </div>
                        <div class="table-scroll" tabindex="0" role="region" aria-label="Accounts on {{ $group['label'] }}, scrolls sideways">
                            <table class="admin-table">
                                <thead>
                                    <tr><th><span class="sr-only">Choose</span></th><th>Name</th><th>Signed up here</th><th>Voted here</th><th>Sign-ins here</th></tr>
                                </thead>
                                <tbody>
                                    @foreach ($group['accounts'] as $account)
                                        @php($user = $account['user'])
                                        <tr>
                                            <td>
                                                @if ($user->vote_held_at)
                                                    <x-badge variant="danger">On hold</x-badge>
                                                @else
                                                    <input type="checkbox" name="users[]" value="{{ $user->id }}" class="size-4 accent-[var(--primary)]" aria-label="Put {{ $user->fullname }} on hold">
                                                @endif
                                            </td>
                                            <td>
                                                <a href="{{ route('admin.users.show', $user) }}" class="link font-semibold">{{ $user->fullname }}</a>
                                                <span class="block text-xs text-muted">{{ $user->matric_number }}</span>
                                                @if ($user->email)
                                                    <span class="block text-xs text-muted">{{ $user->email }}{{ $user->email_verified_at ? ' (confirmed)' : ' (not confirmed)' }}</span>
                                                @endif
                                                @if ($account['registered_at'] === null && $account['logins'] > 0)
                                                    <x-badge>Only signed in here</x-badge>
                                                @endif
                                            </td>
                                            <td class="whitespace-nowrap">{{ $account['registered_at']?->timezone($zone)->format('j M, g:i a') ?? '–' }}</td>
                                            <td class="whitespace-nowrap">{{ $account['voted_at']?->timezone($zone)->format('j M, g:i a') ?? '–' }}</td>
                                            <td>{{ $account['logins'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </x-card>
                @endforeach
            </div>

            <div class="mt-6 grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
                <x-field name="reason" label="Why (kept in the audit log)" :value="$kind === 'device' ? 'Same device as other accounts' : 'Same network and browser as other accounts'" />
                <x-button icon="user-lock">Put ticked accounts on hold</x-button>
            </div>
        </form>
    @endif
</x-layouts.admin>
