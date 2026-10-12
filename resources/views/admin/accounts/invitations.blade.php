@php
    $tabs = ['' => 'All unused', 'waiting' => 'Waiting to send', 'sent' => 'Sent', 'expired' => 'Expired'];
    $total = array_sum($counts);
    $tz = config('app.display_timezone');
@endphp

<x-layouts.admin title="Invitations">
    <x-page-header title="Invitations" :subtitle="'Accounts emailed a link to choose their password who haven\'t used it yet. Invitations go out about '.$perHour.' an hour, and links work for '.config('mail.invitations.expire_days').' days. '.number_format($accepted).' '.\Illuminate\Support\Str::plural('invitation', $accepted).' used so far.'" :back="route('admin.accounts.index')" back-label="Create accounts" />

    @if (session('next'))
        <form method="POST" action="{{ route('admin.accounts.store') }}" class="mb-6">
            @csrf
            @foreach (session('next')['classes'] as $class)
                <input type="hidden" name="classes[]" value="{{ $class }}">
            @endforeach
            <input type="hidden" name="department_id" value="{{ session('next')['department_id'] }}">
            <input type="hidden" name="send" value="email">
            <x-alert type="info">
                More students in these classes still need an account.
                <button type="submit" class="link font-semibold">Create the next batch</button>
            </x-alert>
        </form>
    @endif

    @if ($total === 0)
        <x-empty-state icon="mail" tone="green" title="No unused invitations" text="Everyone invited has chosen their password. To invite more, create accounts with “Create accounts and email invitations”.">
            <x-button href="{{ route('admin.accounts.index') }}" variant="secondary" icon="user-plus">Create accounts</x-button>
        </x-empty-state>
    @else
        <nav class="review-tabs" aria-label="Show">
            @foreach ($tabs as $value => $label)
                <a href="{{ route('admin.accounts.invitations', $value === '' ? [] : ['status' => $value]) }}"
                    class="review-tab" @if ($status === $value || ($value === '' && ! array_key_exists($status, $tabs))) aria-current="page" @endif>
                    {{ $label }} <span class="review-tab-count">{{ number_format($value === '' ? $total : $counts[$value]) }}</span>
                </a>
            @endforeach
        </nav>

        @if ($counts['expired'] > 0)
            <form method="POST" action="{{ route('admin.accounts.invitations.expired') }}" class="mt-4">
                @csrf
                <x-button variant="secondary" size="sm" icon="send">Re-send all {{ number_format($counts['expired']) }} expired</x-button>
            </form>
        @endif

        @if ($invitations->isEmpty())
            <x-empty-state class="mt-6" icon="mail" tone="green" title="None here" text="No invitations in this list right now." />
        @else
            <div class="table-scroll mt-6" tabindex="0" role="region" aria-label="Table, scrolls sideways">
                <table class="admin-table">
                    <thead>
                        <tr><th>Student</th><th>Matric number</th><th>Status</th><th><span class="sr-only">Actions</span></th></tr>
                    </thead>
                    <tbody>
                        @foreach ($invitations as $invitation)
                            @php($user = $invitation->user)
                            @php($bag = 'invitation-'.$invitation->id)
                            <tr id="invitation-{{ $invitation->id }}">
                                <td>
                                    <span class="block font-semibold">{{ $user->fullname }}</span>
                                    <span class="block text-xs text-muted">{{ $user->email ?? 'No email' }} · {{ $user->classLabel() }}</span>
                                </td>
                                <td class="whitespace-nowrap">{{ $user->matric_number }}</td>
                                <td class="whitespace-nowrap">
                                    @switch ($invitation->status())
                                        @case ('waiting')
                                            <x-badge>Waiting to send</x-badge>
                                            @break
                                        @case ('expired')
                                            <x-badge variant="danger">Expired</x-badge>
                                            <span class="block text-xs text-muted">{{ $user->email === null ? 'No email address' : 'Sent '.$invitation->sent_at?->timezone($tz)->format('j M') }}</span>
                                            @break
                                        @default
                                            <x-badge variant="primary">Sent</x-badge>
                                            <span class="block text-xs text-muted">{{ $invitation->sent_at?->timezone($tz)->format('j M, g:i a') }}@if ($invitation->sends > 1) · {{ $invitation->sends }} times @endif</span>
                                    @endswitch
                                </td>
                                <td>
                                    <div class="flex flex-wrap items-start gap-2">
                                        @if ($invitation->status() !== 'waiting' && $user->email !== null)
                                            <form method="POST" action="{{ route('admin.accounts.invitations.resend', $invitation) }}">
                                                @csrf
                                                <x-button variant="secondary" size="sm" icon="send">Re-send</x-button>
                                            </form>
                                        @endif
                                        <details class="invitation-fix" @if ($errors->getBag($bag)->any()) open @endif>
                                            <summary class="link text-sm">{{ $user->email === null ? 'Add email' : 'Wrong address?' }}</summary>
                                            <form method="POST" action="{{ route('admin.accounts.invitations.resend', $invitation) }}" class="mt-2 flex flex-wrap items-end gap-2" novalidate>
                                                @csrf
                                                <div class="min-w-56 flex-1">
                                                    <x-field name="email" id="email-{{ $invitation->id }}" label="Email address" type="email" :value="$user->email" :bag="$bag" :old="$errors->getBag($bag)->any()" />
                                                </div>
                                                <x-button size="sm" icon="send">Save and send</x-button>
                                            </form>
                                        </details>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{ $invitations->links('partials.pagination') }}
        @endif
    @endif
</x-layouts.admin>
