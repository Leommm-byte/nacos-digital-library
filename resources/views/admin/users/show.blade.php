@php
    $zone = config('app.display_timezone');
    $self = auth()->user()?->is($user);
    $slip = session('slip');
    $roleOptions = collect(\App\Enums\Role::cases())->mapWithKeys(fn ($r) => [$r->value => $r->label()])->all();
    $levelOptions = collect(\App\Enums\Level::cases())->mapWithKeys(fn ($l) => [$l->value => $l->label()])->all();
    $programmeOptions = collect(\App\Enums\Programme::cases())->mapWithKeys(fn ($p) => [$p->value => $p->label()])->all();
@endphp

<x-layouts.admin :title="$user->fullname">
    <x-page-header :title="$user->fullname" :subtitle="$user->matric_number.' · '.$user->level->label().' '.$user->programme->label().' · '.$user->department->name" :back="route('admin.users.index')" back-label="Users">
        <x-slot:actions>
            <x-badge :variant="$user->role === \App\Enums\Role::Student ? 'neutral' : 'primary'">{{ $user->role->label() }}</x-badge>
            @if ($user->isSuspended())
                <x-badge variant="danger">Suspended</x-badge>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($slip)
        <div class="mb-8 space-y-3">
            <x-alert type="warning">Print or copy this now: the password is only shown once. {{ explode(' ', $slip['name'])[0] }} will choose a new one when they log in.</x-alert>
            <div class="slips">
                @include('admin.accounts.slip', ['slip' => $slip])
            </div>
            <x-button type="button" variant="secondary" icon="printer" data-print class="no-print">Print slip</x-button>
        </div>
    @endif

    @if ($self)
        <x-alert type="info" class="mb-6">This is your own account. Another admin has to change its role, status or password.</x-alert>
    @endif

    <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-start">
        <div class="min-w-0 space-y-6">
            <x-card>
                <dl class="profile-summary mt-0 sm:grid-cols-2">
                    <div><dt>Email</dt><dd>{{ $user->email ?? 'Not given' }}@if ($user->email && ! $user->email_verified_at) <span class="text-xs font-normal text-muted">(not confirmed)</span>@endif</dd></div>
                    <div><dt>Nominal roll</dt><dd>{{ $roll ? (trim(($roll->level?->label() ?? '').' '.($roll->programme?->label() ?? '')) ?: 'On the roll') : 'Not on the roll' }}</dd></div>
                    <div><dt>Two-step verification</dt><dd>{{ $user->hasTwoFactorEnabled() ? 'On' : 'Off' }}</dd></div>
                    <div><dt>Last login</dt><dd>{{ $user->last_login_at?->timezone($zone)->format('j M Y, g:i a') ?? 'Never' }}</dd></div>
                    <div><dt>Joined</dt><dd>{{ $user->created_at->timezone($zone)->format('j M Y') }}</dd></div>
                    <div><dt>Uploads</dt><dd>{{ number_format($uploads) }}</dd></div>
                </dl>
            </x-card>

            <x-section title="Recent activity" description="What they did, and what was done to their account." icon="scroll-text" tone="blue">
                @if ($activity->isEmpty())
                    <p class="text-sm text-muted">Nothing yet.</p>
                @else
                    <ol class="activity-list dashboard-card mt-0">
                        @foreach ($activity as $entry)
                            <li>
                                <x-icon name="scroll-text" class="activity-icon" />
                                <span class="min-w-0">
                                    <span class="block">{{ \App\Support\AuditActions::label($entry->action) }}@if ($entry->user_id !== $user->id) <span class="text-muted">by {{ $entry->user?->fullname ?? 'the system' }}</span>@endif</span>
                                    <span class="activity-time">{{ $entry->created_at->timezone($zone)->format('j M Y, g:i a') }}</span>
                                </span>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </x-section>
        </div>

        @unless ($self)
            <aside class="space-y-4">
                <x-card class="space-y-3">
                    <h2 class="text-base">Role</h2>
                    <form method="POST" action="{{ route('admin.users.role', $user) }}" class="space-y-3" data-confirm="Change {{ $user->firstName() }}'s role?">
                        @csrf
                        @method('PUT')
                        <x-select name="role" label="Role" :options="$roleOptions" :value="$user->role->value" />
                        <p class="text-xs text-muted">Course reps give reset codes to their class; governors also review uploads and post announcements; admins run everything.</p>
                        <x-button size="sm" variant="secondary" class="w-full">Save role</x-button>
                    </form>
                </x-card>

                <x-card class="space-y-3">
                    <h2 class="text-base">Class</h2>
                    <form method="POST" action="{{ route('admin.users.class', $user) }}" class="space-y-3">
                        @csrf
                        @method('PUT')
                        <div class="grid grid-cols-2 gap-3">
                            <x-select name="level" label="Level" :options="$levelOptions" :value="$user->level->value" />
                            <x-select name="programme" label="Programme" :options="$programmeOptions" :value="$user->programme->value" />
                        </div>
                        <x-select name="department_id" label="Department" :options="$departments" :value="$user->department_id" />
                        <x-button size="sm" variant="secondary" class="w-full">Save class</x-button>
                    </form>
                </x-card>

                <x-card class="space-y-3">
                    <h2 class="text-base">Sign-in help</h2>
                    <form method="POST" action="{{ route('admin.users.password', $user) }}" data-confirm="Give {{ $user->firstName() }} a new password? Their current one stops working.">
                        @csrf
                        <x-button size="sm" variant="secondary" icon="key-round" class="w-full">New password slip</x-button>
                    </form>
                    @if ($user->hasTwoFactorEnabled())
                        <form method="POST" action="{{ route('admin.users.two-factor', $user) }}" data-confirm="Turn off two-step verification for {{ $user->firstName() }}? Only do this after checking who they are.">
                            @csrf
                            @method('DELETE')
                            <x-button size="sm" variant="secondary" icon="shield-off" class="w-full">Turn off two-step</x-button>
                        </form>
                    @endif
                </x-card>

                <form method="POST" action="{{ route('admin.users.status', $user) }}" class="px-1"
                    data-confirm="{{ $user->isSuspended() ? 'Reactivate '.$user->firstName().'\'s account?' : 'Suspend '.$user->firstName().'? They are signed out at once and can\'t log in.' }}">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="status" value="{{ $user->isSuspended() ? 'active' : 'suspended' }}">
                    <button type="submit" @class(['link text-sm', 'text-danger' => ! $user->isSuspended()])>{{ $user->isSuspended() ? 'Reactivate this account' : 'Suspend this account' }}</button>
                </form>
            </aside>
        @endunless
    </div>
</x-layouts.admin>
