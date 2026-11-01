<x-layouts.vote title="Log in">
    <div class="vote-auth">
        <x-card class="space-y-6">
            <div>
                <h1 class="text-2xl">Log in to vote</h1>
                <p class="mt-1 text-muted">Use the account you registered for {{ $organization->name }} elections. Your NACOS account doesn't work here.</p>
            </div>

            <form method="POST" action="{{ route('vote.login.store') }}" class="space-y-4" novalidate>
                @csrf
                <x-field name="login" label="Matric number" placeholder="F/ND/24/1234567" autocomplete="username" autocapitalize="characters" spellcheck="false" required autofocus hint="Department admins can use their email instead." />
                <div>
                    <x-field name="password" label="Password" type="password" autocomplete="current-password" required />
                    <p class="mt-1.5 text-right text-sm"><a href="{{ route('vote.password.forgot') }}" class="link">Forgot password?</a></p>
                </div>
                <label class="flex items-center gap-2.5 text-sm">
                    <input type="checkbox" name="remember" value="1" class="size-4 accent-[var(--primary)]" @checked(old('remember'))>
                    Keep me logged in on this device
                </label>
                <x-button class="w-full" icon="log-in">Log in</x-button>
            </form>

            <p class="text-center text-sm text-muted">Not registered yet? <a href="{{ route('vote.register') }}" class="link">Register to vote</a></p>
        </x-card>
    </div>
</x-layouts.vote>
