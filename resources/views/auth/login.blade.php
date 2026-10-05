<x-layouts.guest title="Log in">
    <h1 class="text-2xl">Welcome back</h1>
    <p class="mt-1 text-muted">Log in with your matric number.</p>

    <form method="POST" action="{{ route('login.store') }}" class="mt-6 space-y-4" novalidate>
        @csrf

        <x-field name="matric_number" label="Matric number" placeholder="F/ND/24/1234567" autocomplete="username" autocapitalize="characters" spellcheck="false" required autofocus class="uppercase placeholder:normal-case" />
        <div>
            <x-field name="password" label="Password" type="password" autocomplete="current-password" required />
            <p class="mt-1.5 text-right text-sm"><a href="{{ route('password.request') }}" class="link">Forgot password?</a></p>
        </div>

        <label class="flex items-center gap-2.5 text-sm">
            <input type="checkbox" name="remember" value="1" class="size-4 accent-[var(--primary)]" @checked(old('remember'))>
            Keep me logged in on this device
        </label>

        <x-button class="w-full" icon="log-in">Log in</x-button>
    </form>

    <p class="mt-6 text-center text-sm text-muted">
        New here? <a href="{{ route('signup') }}" class="link">Create an account</a>
    </p>
</x-layouts.guest>
