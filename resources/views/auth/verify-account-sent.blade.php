<x-layouts.guest title="Check your class email">
    <x-icon-tile name="mail" tone="green" />
    <h1 class="mt-4 text-2xl">Check your class email</h1>
    <p class="mt-2 text-muted">We've sent a one-time link to your class email: <strong class="text-fg">{{ $email }}</strong></p>
    <p class="mt-2 text-muted">Open it within {{ config('mail.invitations.expire_days') }} days to confirm your account and set a password. It can take a few minutes, and emails go out a few at a time, so check your spam folder too.</p>
    <p class="mt-2 text-muted">Not your address? Ask a NACOS admin to correct your email on the nominal roll.</p>

    <x-button href="{{ route('login') }}" variant="secondary" icon="log-in" class="mt-6 w-full">Back to log in</x-button>
</x-layouts.guest>
