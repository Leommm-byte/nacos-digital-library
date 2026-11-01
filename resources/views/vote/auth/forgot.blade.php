<x-layouts.vote title="Forgot password">
    <div class="vote-auth">
        <x-card class="space-y-5">
            <x-icon-tile name="key-round" tone="yellow" />
            <div>
                <h1 class="text-2xl">Forgot your password?</h1>
                <p class="mt-1 text-muted">If you gave an email when you registered, we'll send a link to choose a new one.</p>
            </div>
            <form method="POST" action="{{ route('vote.password.email') }}" class="space-y-4" novalidate>
                @csrf
                <x-field name="login" label="Matric number or email" autocomplete="username" autocapitalize="characters" spellcheck="false" required autofocus />
                <x-button class="w-full" icon="send">Send the link</x-button>
            </form>
            <p class="text-sm text-muted">No email on your account? Ask {{ $organization->name }}'s election admins (your class rep can tell you who) for a new password: they'll give you a slip with a temporary one.</p>
            <p class="text-center text-sm"><a href="{{ route('vote.login') }}" class="link">Back to log in</a></p>
        </x-card>
    </div>
</x-layouts.vote>
