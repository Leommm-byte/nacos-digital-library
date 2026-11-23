<x-layouts.guest title="Check your email">
    <x-icon-tile name="mail" tone="green" />
    <h1 class="mt-4 text-2xl">Check your email</h1>
    <p class="mt-2 text-muted">We've sent a one-time link to the email on record for you: <strong class="text-fg">{{ $email }}</strong></p>
    <p class="mt-2 text-muted">Open it within {{ config('mail.invitations.expire_days') }} days to confirm your account and set a password. It can take a few minutes, and emails go out a few at a time, so check your spam folder too.</p>
    <p class="mt-2 text-muted">Don't recognise this address, or can't open it? Contact a NACOS admin to update it.</p>

    <x-button href="{{ route('login') }}" variant="secondary" icon="log-in" class="mt-6 w-full">Back to log in</x-button>
</x-layouts.guest>
