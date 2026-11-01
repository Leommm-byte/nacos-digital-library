@php($zone = config('app.display_timezone'))

<x-layouts.vote-admin title="Voters">
    <x-page-header title="Voters" :subtitle="number_format($registered).' of '.number_format($rollTotal).' students on the roll have registered.'" />

    @if ($slip)
        <div class="mb-8 space-y-3">
            <x-alert type="warning">Print or copy this now: the password is only shown once. They'll choose their own when they log in.</x-alert>
            <div class="slips">
                @include('vote.partials.slip', ['slip' => $slip])
            </div>
            <x-button type="button" variant="secondary" icon="printer" data-print class="no-print">Print slip</x-button>
        </div>
    @endif

    <form method="GET" action="{{ route('vote.admin.voters') }}" role="search" class="relative mb-6 max-w-xl">
        <label for="voter-search" class="sr-only">Find a voter</label>
        <x-icon name="search" class="pointer-events-none absolute top-1/2 left-4 -translate-y-1/2 text-muted" />
        <input id="voter-search" name="q" type="search" value="{{ $search }}" placeholder="Name, matric number or email" class="field-input pl-11">
    </form>

    @if ($voters->isEmpty())
        <x-empty-state icon="users" title="{{ $search !== '' ? 'No one found' : 'No one has registered yet' }}" text="{{ $search !== '' ? 'Try another name or matric number.' : 'Share this space\'s address with the department, so students on the roll can register: '.route('vote.register') }}" />
    @else
        <div class="table-scroll" tabindex="0" role="region" aria-label="Table, scrolls sideways">
            <table class="admin-table">
                <thead>
                    <tr><th>Name</th><th>Matric number</th><th>Registered</th><th>Status</th><th><span class="sr-only">Actions</span></th></tr>
                </thead>
                <tbody>
                    @foreach ($voters as $voter)
                        @php($self = $voter->is(auth('voter')->user()))
                        <tr>
                            <td>
                                <span class="block font-semibold">{{ $voter->fullname }}</span>
                                <span class="block text-xs text-muted">{{ $voter->email ?? 'No email' }}</span>
                            </td>
                            <td class="whitespace-nowrap">{{ $voter->matric_number ?? '—' }}</td>
                            <td class="whitespace-nowrap">{{ $voter->created_at->timezone($zone)->format('j M Y') }}</td>
                            <td>
                                <span class="flex flex-wrap gap-1">
                                    @if ($voter->isAdmin())
                                        <x-badge variant="primary">Admin</x-badge>
                                    @endif
                                    @if ($voter->isSuspended())
                                        <x-badge variant="danger">Suspended</x-badge>
                                    @elseif ($voter->must_change_password)
                                        <x-badge>Slip not used</x-badge>
                                    @endif
                                </span>
                            </td>
                            <td>
                                @unless ($self)
                                    <div class="flex justify-end gap-2">
                                        <form method="POST" action="{{ route('vote.admin.voters.password', $voter->id) }}" data-confirm="Give {{ $voter->firstName() }} a new password? Their current one stops working.">
                                            @csrf
                                            <x-button size="sm" variant="secondary" icon="key-round">New password</x-button>
                                        </form>
                                        <form method="POST" action="{{ route('vote.admin.voters.status', $voter->id) }}" @unless ($voter->isSuspended()) data-confirm="Suspend {{ $voter->firstName() }}? They can't log in or vote until you reactivate them." @endunless>
                                            @csrf
                                            @method('PUT')
                                            <input type="hidden" name="status" value="{{ $voter->isSuspended() ? 'active' : 'suspended' }}">
                                            <x-button size="sm" variant="ghost">{{ $voter->isSuspended() ? 'Reactivate' : 'Suspend' }}</x-button>
                                        </form>
                                    </div>
                                @endunless
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{ $voters->links('partials.pagination') }}
    @endif
</x-layouts.vote-admin>
