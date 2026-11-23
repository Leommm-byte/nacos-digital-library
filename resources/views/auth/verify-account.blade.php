<x-layouts.guest title="Verify my account">
    <h1 class="text-2xl">Verify your account</h1>
    <p class="mt-1 text-muted">Enter your matric number and we'll send a one-time link to the email NACOS has on record for you. Open it to confirm the account is yours, set a new password, and be able to vote.</p>

    @if (session('verify.signup'))
        <div class="mt-4 rounded-md bg-surface-2 p-4 text-sm">
            <p class="font-semibold">No account for that matric number yet</p>
            <p class="mt-1 text-muted">Create one on the <a href="{{ route('signup') }}" class="link">sign-up page</a>, and we'll send the link to the email on record for you.</p>
        </div>
    @endif

    @if (session('verify.problem'))
        <x-alert type="error" class="mt-4">{{ session('verify.problem') }}</x-alert>
    @endif

    <form method="POST" action="{{ route('verify-account.store') }}" class="mt-6 space-y-4" novalidate>
        @csrf

        <x-field name="matric_number" label="Matric number" placeholder="F/ND/24/1234567" autocomplete="username" autocapitalize="characters" spellcheck="false" required autofocus class="uppercase placeholder:normal-case" />

        <x-button class="w-full" icon="mail">Email me the link</x-button>
    </form>

    <div class="mt-6 rounded-md bg-surface-2 p-4 text-sm">
        <p class="font-semibold">Someone registered or voted as you?</p>
        <p class="mt-1 text-muted">This is how you take the account back. The link only goes to the email on record for you, so whoever made the account can't receive it. Opening it signs everyone else out.</p>
    </div>

    <p class="mt-6 text-center text-sm text-muted">
        Know your password? <a href="{{ route('login') }}" class="link">Log in</a>
    </p>
</x-layouts.guest>
