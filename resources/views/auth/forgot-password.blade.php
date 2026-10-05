<x-layouts.guest title="Forgot password">
    <h1 class="text-2xl">Forgot your password?</h1>
    <p class="mt-1 text-muted">Enter your matric number. If your account has a verified email, we'll send it a reset link.</p>

    <form method="POST" action="{{ route('password.email') }}" class="mt-6 space-y-4" novalidate>
        @csrf

        <x-field name="matric_number" label="Matric number" placeholder="F/ND/24/1234567" autocomplete="username" autocapitalize="characters" spellcheck="false" required autofocus class="uppercase placeholder:normal-case" />

        <x-button class="w-full">Email me a reset link</x-button>
    </form>

    <div class="mt-6 rounded-md bg-surface-2 p-4 text-sm">
        <p class="font-semibold">No email on your account?</p>
        <p class="mt-1 text-muted">Ask your course rep or an admin for a one-time reset code, then <a href="{{ route('password.code') }}" class="link">reset with a code</a>.</p>
    </div>

    <p class="mt-6 text-center text-sm text-muted">
        Remembered it? <a href="{{ route('login') }}" class="link">Log in</a>
    </p>
</x-layouts.guest>
