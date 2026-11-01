<x-layouts.vote title="Forgot password">
    <div class="vote-auth">
        <x-card class="space-y-4">
            <x-icon-tile name="key-round" tone="yellow" />
            <h1 class="text-2xl">Forgot your password?</h1>
            <p class="text-muted">Ask {{ $organization->name }}'s election admins (your class rep can tell you who) for a new password. They'll give you a slip with a temporary password; you choose your own when you log in.</p>
            <x-button href="{{ route('vote.login') }}" variant="secondary" icon="log-in">Back to log in</x-button>
        </x-card>
    </div>
</x-layouts.vote>
