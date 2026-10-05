<x-layouts.guest title="Choose a new password">
    <h1 class="text-2xl">Choose a new password</h1>
    <p class="mt-1 text-muted">You signed in with a temporary password. Choose your own to continue.</p>

    <form method="POST" action="{{ route('password.change.update') }}" class="mt-6 space-y-4" novalidate>
        @csrf
        @method('PUT')

        <x-field name="password" label="New password" type="password" autocomplete="new-password" required autofocus aria-describedby="password-rules">
            <ul id="password-rules" class="password-rules" data-password-rules="password">
                <li data-rule="length">At least 8 characters</li>
                <li data-rule="case">Upper and lower case letters</li>
                <li data-rule="number">A number</li>
                <li data-rule="symbol">A symbol, like ! or #</li>
            </ul>
        </x-field>
        <x-field name="password_confirmation" label="Confirm new password" type="password" autocomplete="new-password" required />

        <x-button class="w-full">Save password</x-button>
    </form>

    <form method="POST" action="{{ route('logout') }}" class="mt-4 text-center">
        @csrf
        <button type="submit" class="text-sm text-muted underline underline-offset-4">Log out instead</button>
    </form>
</x-layouts.guest>
