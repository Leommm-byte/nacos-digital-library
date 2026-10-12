<x-layouts.guest title="Choose your password">
    <h1 class="text-2xl">Welcome, {{ $user->greetingName() }}</h1>
    <p class="mt-1 text-muted">Choose a password for your account. You'll log in with your matric number, <strong class="text-fg">{{ $user->matric_number }}</strong>, and this password.</p>

    <form method="POST" action="{{ route('invitation.store', $token) }}" class="mt-6 space-y-4" novalidate>
        @csrf
        <x-field name="password" label="Password" type="password" autocomplete="new-password" required autofocus aria-describedby="password-rules">
            <x-password-rules for="password" />
        </x-field>
        <x-field name="password_confirmation" label="Confirm password" type="password" autocomplete="new-password" required />

        <x-button class="w-full">Save password and continue</x-button>
    </form>
</x-layouts.guest>
