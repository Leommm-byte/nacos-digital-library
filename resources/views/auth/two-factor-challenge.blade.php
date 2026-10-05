<x-layouts.guest title="Two-step verification">
    <h1 class="text-2xl">Two-step verification</h1>
    <p class="mt-1 text-muted">Enter the 6-digit code from your authenticator app. Lost your phone? Enter one of your recovery codes instead.</p>

    <form method="POST" action="{{ route('two-factor.login.store') }}" class="mt-6 space-y-4" novalidate>
        @csrf

        <x-field name="code" label="Code" autocomplete="one-time-code" autocapitalize="characters" spellcheck="false" required autofocus class="text-center font-mono text-lg tracking-[0.3em]" />

        <x-button class="w-full">Verify and log in</x-button>
    </form>

    <p class="mt-6 text-center text-sm text-muted">
        Can't get a code? Ask an admin to turn off two-step verification for your account.
        <br><a href="{{ route('login') }}" class="link">Start again</a>
    </p>
</x-layouts.guest>
