<x-layouts.guest title="Check your email">
    <x-icon-tile name="mail" tone="green" />
    <h1 class="mt-4 text-2xl">Check your email</h1>
    <p class="mt-2 text-muted">Your class list has an email address for this matric number, so we've sent the link to finish signing up there: <strong class="text-fg">{{ $email }}</strong></p>
    <p class="mt-2 text-muted">Open it within {{ config('mail.invitations.expire_days') }} days to choose your password. It can take a few minutes to arrive, so check your spam folder too.</p>
    <p class="mt-2 text-muted">Not your address? Ask a NACOS admin to correct it on the class list.</p>

    <x-button href="{{ route('login') }}" variant="secondary" icon="log-in" class="mt-6 w-full">Back to log in</x-button>
</x-layouts.guest>
