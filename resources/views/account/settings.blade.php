<x-layouts.app title="Account settings">
    <x-page-header title="Account settings" subtitle="Your password, email and sign-in security.">
        <x-slot:actions>
            <x-button href="{{ route('profile.edit') }}" variant="secondary" icon="user">Edit profile</x-button>
        </x-slot:actions>
    </x-page-header>

    @if (session('recovery_codes'))
        <div class="recovery-codes animate-enter mb-10">
            <div class="flex items-start gap-4">
                <x-icon-tile name="key-round" tone="yellow" />
                <div class="min-w-0">
                    <h2 class="text-lg">Save your recovery codes</h2>
                    <p class="mt-1 text-sm text-muted">If you lose your phone, each code lets you log in once. Write them down or take a screenshot and keep them somewhere safe. <strong class="text-fg">They won't be shown again.</strong></p>
                </div>
            </div>
            <ul class="mt-5 grid grid-cols-2 gap-2 sm:grid-cols-4">
                @foreach (session('recovery_codes') as $code)
                    <li class="recovery-code">{{ $code }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="settings">
        <section class="settings-row" aria-labelledby="password-heading">
            <div class="settings-label">
                <x-icon-tile name="key-round" tone="green" size="sm" />
                <div>
                    <h2 id="password-heading">Password</h2>
                    <p>Changing it logs you out on every other device.</p>
                </div>
            </div>
            <x-card>
                <form method="POST" action="{{ route('settings.password') }}" class="space-y-4" novalidate>
                    @csrf
                    @method('PUT')
                    <x-field name="current_password" id="password_current_password" label="Current password" type="password" autocomplete="current-password" bag="password" required />
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-field name="password" id="new_password" label="New password" type="password" autocomplete="new-password" bag="password" required aria-describedby="new_password-rules" />
                        <x-field name="password_confirmation" id="new_password_confirmation" label="Confirm new password" type="password" autocomplete="new-password" bag="password" required />
                    </div>
                    <x-password-rules for="new_password" />
                    <div class="settings-actions">
                        <x-button>Change password</x-button>
                    </div>
                </form>
            </x-card>
        </section>

        <section class="settings-row" aria-labelledby="email-heading">
            <div class="settings-label">
                <x-icon-tile name="mail" tone="blue" size="sm" />
                <div>
                    <h2 id="email-heading">Email address</h2>
                    <p>Where we send password reset links.</p>
                </div>
            </div>
            <x-card>
                <div class="settings-status">
                    <div class="min-w-0">
                        <p class="text-sm text-muted">Current email</p>
                        <p class="truncate font-semibold">{{ $user->email ?? 'Not set' }}</p>
                    </div>
                    @if ($user->email && $user->hasVerifiedEmail())
                        <x-badge variant="primary"><x-icon name="circle-check" class="size-3.5" /> Verified</x-badge>
                    @elseif ($user->email)
                        <x-badge variant="accent">Not verified</x-badge>
                    @else
                        <x-badge>Not set</x-badge>
                    @endif
                </div>

                @if ($user->email && ! $user->hasVerifiedEmail())
                    <form method="POST" action="{{ route('verification.send') }}" class="mt-4">
                        @csrf
                        <x-alert type="warning">
                            Check your inbox for the link we sent. Didn't get it? Look in spam, or <button type="submit" class="link">send it again</button>.
                        </x-alert>
                    </form>
                @endif

                <form method="POST" action="{{ route('settings.email') }}" class="mt-5 space-y-4 border-t border-border pt-5" novalidate>
                    @csrf
                    @method('PUT')
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-field name="email" label="{{ $user->email ? 'New email address' : 'Email address' }}" type="email" autocomplete="email" bag="email" required />
                        <x-field name="current_password" id="email_current_password" label="Current password" type="password" autocomplete="current-password" bag="email" required />
                    </div>
                    <div class="settings-actions">
                        <x-button variant="secondary">{{ $user->email ? 'Change email' : 'Add email' }}</x-button>
                    </div>
                </form>
            </x-card>
        </section>

        <section class="settings-row" aria-labelledby="two-factor-heading">
            <div class="settings-label">
                <x-icon-tile name="shield-check" tone="violet" size="sm" />
                <div>
                    <h2 id="two-factor-heading">Two-step verification</h2>
                    <p>After your password, also ask for a code from an app on your phone, so a stolen password isn't enough.</p>
                </div>
            </div>
            <x-card>
                <div class="settings-status">
                    <div class="min-w-0">
                        <p class="text-sm text-muted">Status</p>
                        <p class="font-semibold">{{ $user->hasTwoFactorEnabled() ? 'On' : 'Off' }}</p>
                    </div>
                    @if ($user->hasTwoFactorEnabled())
                        <x-badge variant="primary"><x-icon name="shield-check" class="size-3.5" /> Protected</x-badge>
                    @else
                        <x-badge>Off</x-badge>
                    @endif
                </div>

                @if (! $user->hasTwoFactorEnabled())
                    <form method="POST" action="{{ route('two-factor.start') }}" class="mt-5 space-y-4 border-t border-border pt-5" novalidate>
                        @csrf
                        <x-field name="current_password" id="two_factor_current_password" label="Confirm with your password" type="password" autocomplete="current-password" bag="twoFactor" required />
                        <div class="settings-actions">
                            <x-button icon="shield-check">Turn on</x-button>
                        </div>
                    </form>
                @else
                    <p class="mt-4 text-sm">
                        Recovery codes left: <strong>{{ $recoveryCodesLeft }}</strong>
                        @if ($recoveryCodesLeft <= 2)
                            <span class="text-danger">· create new ones soon</span>
                        @endif
                    </p>

                    <div class="mt-5 grid gap-6 border-t border-border pt-5 sm:grid-cols-2">
                        <form method="POST" action="{{ route('two-factor.recovery-codes') }}" class="space-y-3" novalidate>
                            @csrf
                            <x-field name="code" id="recovery_code" label="Code from your app" autocomplete="one-time-code" bag="recoveryCodes" required />
                            <x-button variant="secondary">New recovery codes</x-button>
                        </form>

                        <form method="POST" action="{{ route('two-factor.destroy') }}" class="space-y-3" novalidate>
                            @csrf
                            @method('DELETE')
                            <x-field name="code" id="disable_code" label="Code from your app" autocomplete="one-time-code" bag="disableTwoFactor" required />
                            <x-button variant="danger">Turn off</x-button>
                        </form>
                    </div>
                @endif
            </x-card>
        </section>

        @can('issue-reset-codes')
            <section class="settings-row" aria-labelledby="codes-heading">
                <div class="settings-label">
                    <x-icon-tile name="key-round" tone="yellow" size="sm" />
                    <div>
                        <h2 id="codes-heading">Helping classmates</h2>
                        <p>For students who forgot their password and have no email.</p>
                    </div>
                </div>
                <x-card class="flex flex-wrap items-center justify-between gap-4">
                    <p class="text-sm text-muted">Issue a one-time reset code for a student in your class.</p>
                    <x-button href="{{ route('reset-codes.create') }}" variant="secondary" icon="key-round">Issue a reset code</x-button>
                </x-card>
            </section>
        @endcan
    </div>
</x-layouts.app>
