@php($zone = config('app.display_timezone'))

<x-layouts.admin title="Vote holds">
    <x-page-header title="Vote holds" :subtitle="number_format($total).' '.\Illuminate\Support\Str::plural('account', $total).' on hold. They can sign in, but can\'t vote until an admin has seen the student in person and released them.'">
        <x-slot:actions>
            <x-button href="{{ route('admin.vote-holds.devices') }}" icon="smartphone">Shared devices</x-button>
        </x-slot:actions>
    </x-page-header>

    <x-alert type="info" class="mb-6">On election day, someone whose account is on hold comes to you with their student ID. Find them here, check it's them, and release them: they can vote straight away. Live results show how many accounts are still on hold, the most extra ballots there can be.</x-alert>

    <form method="GET" action="{{ route('admin.vote-holds.index') }}" class="filter-bar mb-6" data-autosubmit>
        <div class="filter-search relative">
            <label for="hold-search" class="field-label">Search</label>
            <input id="hold-search" name="q" type="search" value="{{ $search }}" placeholder="Name or matric number" class="field-input">
        </div>
        <div class="filter-actions"><x-button variant="secondary" icon="search">Find</x-button></div>
    </form>

    @if ($held->isEmpty())
        <x-empty-state icon="user-check" :title="$search !== '' ? 'Nobody found' : 'No accounts on hold'" :text="$search !== '' ? 'Nobody on hold matches that name or matric number.' : 'Put accounts on hold from Shared devices, or from a person\'s page under Users.'" />
    @else
        <div class="table-scroll" tabindex="0" role="region" aria-label="Table, scrolls sideways">
            <table class="admin-table">
                <thead>
                    <tr><th>Name</th><th>On hold since</th><th>Why</th><th><span class="sr-only">Release</span></th></tr>
                </thead>
                <tbody>
                    @foreach ($held as $user)
                        <tr>
                            <td>
                                <a href="{{ route('admin.users.show', $user) }}" class="user-cell">
                                    <span class="user-initial" aria-hidden="true">{{ mb_strtoupper(mb_substr($user->fullname, 0, 1)) }}</span>
                                    <span class="min-w-0">
                                        <span class="block truncate font-semibold">{{ $user->fullname }}</span>
                                        <span class="block truncate text-xs text-muted">{{ $user->matric_number }}</span>
                                    </span>
                                </a>
                            </td>
                            <td class="whitespace-nowrap">
                                {{ $user->vote_held_at?->timezone($zone)->format('j M, g:i a') }}
                                @if ($user->voteHeldBy)
                                    <span class="block text-xs text-muted">by {{ $user->voteHeldBy->fullname }}</span>
                                @endif
                            </td>
                            <td class="text-sm">{{ $user->vote_hold_reason }}</td>
                            <td>
                                <form method="POST" action="{{ route('admin.users.vote-release', $user) }}" data-confirm="Release {{ $user->firstName() }} ({{ $user->matric_number }})? Only after checking their student ID. They can vote straight away.">
                                    @csrf
                                    @method('DELETE')
                                    <input type="hidden" name="note" value="Saw their student ID in person">
                                    <x-button size="sm" icon="user-check">Release</x-button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{ $held->links('partials.pagination') }}
    @endif
</x-layouts.admin>
