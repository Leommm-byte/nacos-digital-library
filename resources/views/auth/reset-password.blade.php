<x-layouts.guest title="Choose a new password">
    <h1 class="text-2xl">Choose a new password</h1>
    <p class="mt-1 text-muted">You'll be logged out on every other device.</p>

    <form method="POST" action="{{ route('password.update') }}" class="mt-6 space-y-4" novalidate>
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <input type="hidden" name="email" value="{{ old('email', $email) }}">

        <x-field name="password" label="New password" type="password" autocomplete="new-password" required autofocus aria-describedby="password-rules">
            <x-password-rules for="password" />
        </x-field>
        <x-field name="password_confirmation" label="Confirm new password" type="password" autocomplete="new-password" required />

        @error('email')
            <x-alert type="error">{{ $message }}</x-alert>
        @enderror

        <x-button class="w-full">Reset password</x-button>
    </form>
</x-layouts.guest>
