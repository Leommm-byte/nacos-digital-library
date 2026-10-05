<x-layouts.guest title="Create account">
    <h1 class="text-2xl">Create your account</h1>
    <p class="mt-1 text-muted">For students of Computing at Yaba College of Technology.</p>

    <form method="POST" action="{{ route('signup.store') }}" class="mt-6 space-y-4" novalidate>
        @csrf

        <x-field name="fullname" label="Full name" autocomplete="name" required autofocus />
        <x-field name="matric_number" label="Matric number" placeholder="F/ND/24/1234567" hint="As printed on your school ID card." autocomplete="username" autocapitalize="characters" spellcheck="false" required class="uppercase placeholder:normal-case" />

        <x-select name="department_id" label="Department" placeholder="Choose your department" required
            :options="$departments->pluck('name', 'id')->all()" />

        <div class="grid gap-4 sm:grid-cols-2">
            <x-select name="level" label="Level" placeholder="Choose" required
                :options="collect($levels)->mapWithKeys(fn ($level) => [$level->value => $level->label()])->all()" />
            <x-select name="programme" label="Programme" placeholder="Choose" required
                :options="collect($programmes)->mapWithKeys(fn ($programme) => [$programme->value => $programme->label()])->all()" />
        </div>

        <x-field name="password" label="Password" type="password" autocomplete="new-password" required aria-describedby="password-rules">
            <ul id="password-rules" class="password-rules" data-password-rules="password">
                <li data-rule="length">At least 8 characters</li>
                <li data-rule="case">Upper and lower case letters</li>
                <li data-rule="number">A number</li>
                <li data-rule="symbol">A symbol, like ! or #</li>
            </ul>
        </x-field>
        <x-field name="password_confirmation" label="Confirm password" type="password" autocomplete="new-password" required />

        <x-button class="w-full" icon="user-plus">Create account</x-button>
    </form>

    <p class="mt-6 text-center text-sm text-muted">
        Already have an account? <a href="{{ route('login') }}" class="link">Log in</a>
    </p>
</x-layouts.guest>
