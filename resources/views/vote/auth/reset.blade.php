<x-layouts.vote title="Choose a new password">
    <div class="vote-auth">
        <x-card class="space-y-5">
            <h1 class="text-2xl">Choose a new password</h1>
            <form method="POST" action="{{ route('vote.password.reset.store', ['voter' => $voter, 'token' => $token]) }}" class="space-y-4" novalidate>
                @csrf
                <x-field name="password" label="New password" type="password" autocomplete="new-password" required autofocus hint="At least 8 characters." />
                <x-field name="password_confirmation" label="New password again" type="password" autocomplete="new-password" required />
                <x-button class="w-full" icon="key-round">Save password</x-button>
            </form>
        </x-card>
    </div>
</x-layouts.vote>
