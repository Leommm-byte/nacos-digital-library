<x-layouts.guest title="Reset with a code">
    <h1 class="text-2xl">Reset with a code</h1>
    <p class="mt-1 text-muted">Use the one-time code your course rep or an admin gave you. It works once and expires after 30 minutes.</p>

    <form method="POST" action="{{ route('password.code.store') }}" class="mt-6 space-y-4" novalidate>
        @csrf

        <x-field name="matric_number" label="Matric number" placeholder="F/ND/24/1234567" autocomplete="username" autocapitalize="characters" spellcheck="false" required autofocus class="uppercase placeholder:normal-case" />
        <x-field name="code" label="Reset code" placeholder="XXXX-XXXX" autocomplete="off" autocapitalize="characters" spellcheck="false" required class="font-mono uppercase tracking-widest placeholder:normal-case placeholder:tracking-normal" />
        <x-field name="password" label="New password" type="password" autocomplete="new-password" required aria-describedby="password-rules">
            <x-password-rules for="password" />
        </x-field>
        <x-field name="password_confirmation" label="Confirm new password" type="password" autocomplete="new-password" required />

        <x-button class="w-full">Reset password</x-button>
    </form>

    <p class="mt-6 text-center text-sm text-muted">
        <a href="{{ route('login') }}" class="link">Back to log in</a>
    </p>
</x-layouts.guest>
