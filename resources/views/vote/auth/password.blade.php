<x-layouts.vote title="Change password">
    <div class="vote-auth">
        <x-card class="space-y-6">
            <div>
                <h1 class="text-2xl">{{ $forced ? 'Choose your password' : 'Change password' }}</h1>
                <p class="mt-1 text-muted">{{ $forced ? 'You logged in with a temporary password. Choose your own to continue.' : 'Choose a new password for this election space.' }}</p>
            </div>

            <form method="POST" action="{{ route('vote.password.update') }}" class="space-y-4" novalidate>
                @csrf
                @method('PUT')
                @unless ($forced)
                    <x-field name="current_password" label="Current password" type="password" autocomplete="current-password" required />
                @endunless
                <x-field name="password" label="New password" type="password" autocomplete="new-password" required hint="At least 8 characters." />
                <x-field name="password_confirmation" label="New password again" type="password" autocomplete="new-password" required />
                <x-button class="w-full" icon="key-round">Save password</x-button>
            </form>
        </x-card>
    </div>
</x-layouts.vote>
