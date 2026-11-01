<x-layouts.vote title="Register to vote">
    <div class="vote-auth">
        <x-card class="space-y-6">
            <div>
                <h1 class="text-2xl">Register to vote</h1>
                <p class="mt-1 text-muted">For {{ $organization->name }} elections. Your matric number must be on the department's nominal roll. Register before voting opens: accounts made during an election usually can't vote in it.</p>
            </div>

            <form method="POST" action="{{ route('vote.register.store') }}" class="space-y-4" novalidate>
                @csrf
                <x-field name="fullname" label="Full name" autocomplete="name" hint="As on your school records." required autofocus />
                <x-field name="matric_number" label="Matric number" placeholder="F/ND/24/1234567" hint="As printed on your school ID card." autocomplete="username" autocapitalize="characters" spellcheck="false" required class="uppercase placeholder:normal-case" />
                <x-field name="email" label="Email" type="email" autocomplete="email" hint="So the department can reach you about elections." />
                <x-field name="password" label="Password" type="password" autocomplete="new-password" required hint="At least 8 characters." />
                <x-field name="password_confirmation" label="Password again" type="password" autocomplete="new-password" required />
                <x-button class="w-full" icon="user-plus">Register</x-button>
            </form>

            <p class="text-center text-sm text-muted">Already registered? <a href="{{ route('vote.login') }}" class="link">Log in</a></p>
        </x-card>
    </div>
</x-layouts.vote>
