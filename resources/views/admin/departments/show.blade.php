@php($space = route('vote.home', $department))

<x-layouts.admin :title="$department->name">
    <x-page-header :title="$department->name" :subtitle="preg_replace('#^https?://#', '', $space)" :back="route('admin.departments.index')" back-label="Departments">
        <x-slot:actions>
            <x-button href="{{ route('vote.admin.roll', $department) }}" icon="settings">Manage roll and elections</x-button>
            <x-button href="{{ route('vote.admin.activity', $department) }}" variant="secondary" icon="scroll-text">Activity log</x-button>
            <x-button href="{{ $space }}" variant="secondary" icon="eye">Open its space</x-button>
            <x-button href="{{ route('admin.departments.edit', $department) }}" variant="secondary" icon="pencil">Edit</x-button>
        </x-slot:actions>
    </x-page-header>

    @unless ($department->isActive())
        <x-alert type="warning" class="mb-6">This department's space is paused: nobody can sign in or vote there until you resume it.</x-alert>
    @endunless

    @if ($slip)
        <div class="mb-8 space-y-3">
            <x-alert type="warning">Print or copy this now: the password is only shown once. Send it to the admin privately; they choose their own when they log in.</x-alert>
            <div class="slips">
                @include('vote.partials.slip', ['slip' => $slip, 'organization' => $department])
            </div>
            <x-button type="button" variant="secondary" icon="printer" data-print class="no-print">Print slip</x-button>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-8 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start">
        <div class="min-w-0 space-y-8">
            <ul class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <li class="stat-tile"><strong>{{ number_format($counts['roll']) }}</strong><small>On the roll</small></li>
                <li class="stat-tile"><strong>{{ number_format($counts['voters']) }}</strong><small>Registered</small></li>
                <li class="stat-tile"><strong>{{ number_format($counts['elections']) }}</strong><small>{{ \Illuminate\Support\Str::plural('Election', $counts['elections']) }}</small></li>
                <li class="stat-tile"><strong>{{ number_format($counts['open']) }}</strong><small>Open now</small></li>
            </ul>

            <x-section title="Admins" description="They upload the department's roll, run its elections and help its voters, signing in at its space with their email. You can do all of it for them with Manage roll and elections." icon="shield-check" tone="green">
                @if ($admins->isEmpty())
                    <x-empty-state icon="user-plus" title="No admin yet" text="Add the person running the department's elections. You'll get a slip with their first password." />
                @else
                    <ul class="upload-list">
                        @foreach ($admins as $admin)
                            <li class="upload-row flex-wrap">
                                <x-icon-tile name="shield-check" tone="green" size="sm" />
                                <span class="min-w-0 flex-1 basis-48">
                                    <span class="upload-row-title">{{ $admin->fullname }}</span>
                                    <span class="upload-row-meta">{{ $admin->email ?? $admin->matric_number }} · {{ $admin->last_login_at ? 'last signed in '.$admin->last_login_at->timezone(config('app.display_timezone'))->format('j M Y') : 'hasn\'t signed in yet' }}</span>
                                </span>
                                <div class="ml-auto flex items-center gap-2">
                                <form method="POST" action="{{ route('admin.departments.admins.password', [$department, $admin->id]) }}" data-confirm="Give {{ $admin->firstName() }} a new password? Their current one stops working.">
                                    @csrf
                                    <x-button size="sm" variant="secondary" icon="key-round">New password</x-button>
                                </form>
                                <form method="POST" action="{{ route('admin.departments.admins.destroy', [$department, $admin->id]) }}" data-confirm="Remove {{ $admin->fullname }} as an admin of {{ $department->name }}?">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="icon-btn icon-btn-danger" aria-label="Remove {{ $admin->fullname }} as admin"><x-icon name="trash-2" /></button>
                                </form>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <form method="POST" action="{{ route('admin.departments.admins.store', $department) }}" class="setup-add mt-4" novalidate>
                    @csrf
                    <x-field name="fullname" id="admin-name" label="Name" bag="admin" required maxlength="150" :old="$errors->getBag('admin')->any()" />
                    <x-field name="email" id="admin-email" label="Email" type="email" bag="admin" required maxlength="255" :old="$errors->getBag('admin')->any()" />
                    <x-button icon="user-plus" variant="secondary">Add admin</x-button>
                </form>
            </x-section>
        </div>

        <aside class="space-y-4">
            <x-card class="space-y-3">
                <h2 class="text-base">Contact</h2>
                <dl class="profile-summary mt-0">
                    <div><dt>Person</dt><dd>{{ $department->contact_name ?? '—' }}</dd></div>
                    <div><dt>Email</dt><dd>{{ $department->contact_email ?? '—' }}</dd></div>
                    <div><dt>Phone</dt><dd>{{ $department->contact_phone ?? '—' }}</dd></div>
                </dl>
                @if ($department->notes)
                    <p class="whitespace-pre-line text-sm text-muted">{{ $department->notes }}</p>
                @endif
            </x-card>

            <x-card class="space-y-3">
                <h2 class="text-base">{{ $department->isActive() ? 'Pause the space' : 'Resume the space' }}</h2>
                <p class="text-sm text-muted">{{ $department->isActive() ? 'For example while payment is outstanding. Nobody can sign in or vote there; open elections still close on time.' : 'Voters and admins can sign in again.' }}</p>
                <form method="POST" action="{{ route('admin.departments.status', $department) }}" @if ($department->isActive()) data-confirm="Pause {{ $department->name }}'s election space?" @endif>
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="status" value="{{ $department->isActive() ? 'suspended' : 'active' }}">
                    <x-button :variant="$department->isActive() ? 'secondary' : 'primary'" class="w-full">{{ $department->isActive() ? 'Pause' : 'Resume' }}</x-button>
                </form>
            </x-card>
        </aside>
    </div>
</x-layouts.admin>
