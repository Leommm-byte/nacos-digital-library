<x-layouts.app title="Account settings">
    <div class="animate-enter max-w-2xl">
        <h1 class="text-2xl sm:text-3xl">Account settings</h1>
        <p class="mt-1 text-muted">{{ $user->fullname }} · {{ $user->matric_number }} · <a href="{{ route('profile.edit') }}" class="link">Edit profile</a></p>
    </div>

    @if (session('recovery_codes'))
        <x-card class="animate-enter mt-8 max-w-2xl border-accent">
            <h2 class="text-lg">Save your recovery codes</h2>
            <p class="mt-1 text-sm text-muted">If you lose your phone, each of these lets you log in once. Write them down or screenshot them and keep them somewhere safe. <strong class="text-fg">They won't be shown again.</strong></p>
            <ul class="mt-4 grid grid-cols-2 gap-2 font-mono text-base sm:grid-cols-4">
                @foreach (session('recovery_codes') as $code)
                    <li class="rounded-md bg-surface-2 px-3 py-2 text-center tracking-wider">{{ $code }}</li>
                @endforeach
            </ul>
        </x-card>
    @endif

    <section class="mt-8 max-w-2xl" aria-labelledby="password-heading">
        <x-card>
            <h2 id="password-heading" class="text-lg">Password</h2>
            <p class="mt-1 text-sm text-muted">Changing it logs you out on every other device.</p>

            <form method="POST" action="{{ route('settings.password') }}" class="mt-5 space-y-4" novalidate>
                @csrf
                @method('PUT')
                <x-field name="current_password" id="password_current_password" label="Current password" type="password" autocomplete="current-password" bag="password" required />
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field name="password" id="new_password" label="New password" type="password" autocomplete="new-password" bag="password" required aria-describedby="new_password-rules">
                        <x-password-rules for="new_password" />
                    </x-field>
                    <x-field name="password_confirmation" id="new_password_confirmation" label="Confirm new password" type="password" autocomplete="new-password" bag="password" required />
                </div>
                <x-button variant="secondary">Change password</x-button>
            </form>
        </x-card>
    </section>

    <section class="mt-6 max-w-2xl" aria-labelledby="email-heading">
        <x-card>
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 id="email-heading" class="text-lg">Email address</h2>
                    <p class="mt-1 text-sm text-muted">Used to send you password reset links.</p>
                </div>
                @if ($user->email && $user->hasVerifiedEmail())
                    <x-badge variant="primary">Verified</x-badge>
                @elseif ($user->email)
                    <x-badge variant="accent">Not verified</x-badge>
                @else
                    <x-badge>Not set</x-badge>
                @endif
            </div>

            @if ($user->email && ! $user->hasVerifiedEmail())
                <form method="POST" action="{{ route('verification.send') }}" class="mt-4">
                    @csrf
                    <p class="text-sm">We sent a link to <strong>{{ $user->email }}</strong>. Didn't get it? Check spam, or
                        <button type="submit" class="link">send it again</button>.</p>
                </form>
            @endif

            <form method="POST" action="{{ route('settings.email') }}" class="mt-5 grid gap-4 sm:grid-cols-2" novalidate>
                @csrf
                @method('PUT')
                <x-field name="email" label="{{ $user->email ? 'New email address' : 'Email address' }}" type="email" autocomplete="email" bag="email" :value="$user->email" required />
                <x-field name="current_password" id="email_current_password" label="Current password" type="password" autocomplete="current-password" bag="email" required />
                <div class="sm:col-span-2">
                    <x-button variant="secondary">{{ $user->email ? 'Change email' : 'Add email' }}</x-button>
                </div>
            </form>
        </x-card>
    </section>

    <section class="mt-6 max-w-2xl" aria-labelledby="two-factor-heading">
        <x-card>
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 id="two-factor-heading" class="text-lg">Two-step verification</h2>
                    <p class="mt-1 text-sm text-muted">After your password, also ask for a code from an authenticator app on your phone, so a stolen password isn't enough.</p>
                </div>
                @if ($user->hasTwoFactorEnabled())
                    <x-badge variant="primary">On</x-badge>
                @else
                    <x-badge>Off</x-badge>
                @endif
            </div>

            @if (! $user->hasTwoFactorEnabled())
                <form method="POST" action="{{ route('two-factor.start') }}" class="mt-5 grid gap-4 sm:grid-cols-2" novalidate>
                    @csrf
                    <x-field name="current_password" id="two_factor_current_password" label="Current password" type="password" autocomplete="current-password" bag="twoFactor" required />
                    <div class="flex items-end">
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

                <div class="mt-5 grid gap-6 sm:grid-cols-2">
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
        <p class="mt-6 max-w-2xl text-sm text-muted">
            Helping a classmate who forgot their password? <a href="{{ route('reset-codes.create') }}" class="link">Issue a reset code</a>.
        </p>
    @endcan
</x-layouts.app>
