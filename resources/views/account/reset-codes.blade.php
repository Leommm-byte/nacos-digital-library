<x-layouts.app title="Reset codes">
    <x-page-header title="Issue a reset code" subtitle="For a student who forgot their password and can't reset it by email. Check it's really them first, ideally in person." :back="route('settings')" back-label="Account settings" />

    @if ($issued = session('issued_code'))
        <x-card class="animate-enter mb-6 max-w-xl border-accent">
            <p class="text-sm text-muted">Code for <strong class="text-fg">{{ $issued['name'] }}</strong> ({{ $issued['matric_number'] }})</p>
            <p class="mt-2 select-all font-mono text-3xl font-bold tracking-[0.2em]">{{ $issued['code'] }}</p>
            <p class="mt-3 text-sm text-muted">Tell them to open <strong class="text-fg">{{ route('password.code') }}</strong> and enter it with their matric number. It works once and expires in {{ $issued['minutes'] }} minutes. It won't be shown again.</p>
        </x-card>
    @endif

    <x-card class="max-w-xl">
        <form method="POST" action="{{ route('reset-codes.store') }}" class="space-y-4" novalidate>
            @csrf
            <x-field name="matric_number" label="Student's matric number" placeholder="F/ND/24/1234567" autocapitalize="characters" spellcheck="false" required class="uppercase placeholder:normal-case" />
            <p class="text-sm text-muted">Course reps can issue codes for students in their own department and level. Issuing a new code cancels any earlier one.</p>
            <x-button>Issue code</x-button>
        </form>
    </x-card>
</x-layouts.app>
