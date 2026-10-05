<x-layouts.app title="Set up two-step verification">
    <div class="animate-enter max-w-xl">
        <h1 class="text-2xl sm:text-3xl">Set up two-step verification</h1>
        <p class="mt-1 text-muted">You need an authenticator app, such as Google Authenticator, Microsoft Authenticator or Aegis.</p>
    </div>

    <x-card class="mt-8 max-w-xl space-y-6">
        <div>
            <h2 class="text-base">1. Add your account to the app</h2>
            <p class="mt-1 text-sm text-muted">On this phone, tap the button. On a computer, scan the QR code with your phone.</p>

            <div class="mt-4 flex flex-col items-start gap-4 sm:flex-row sm:items-center">
                {{-- Drawn by resources/js/qr.js; the key below works without it. --}}
                <div data-qr="{{ $uri }}" class="qr-code" role="img" aria-label="QR code for your authenticator app"></div>
                <x-button href="{{ $uri }}" variant="secondary" icon="shield-check">Open in authenticator app</x-button>
            </div>

            <p class="mt-4 text-sm text-muted">Or type this key into the app:</p>
            <p class="mt-1 select-all break-all rounded-md bg-surface-2 px-3 py-2 font-mono text-base tracking-wider">{{ $secret }}</p>
        </div>

        <form method="POST" action="{{ route('two-factor.confirm') }}" class="space-y-4" novalidate>
            @csrf
            <h2 class="text-base">2. Enter the 6-digit code the app shows</h2>
            <x-field name="code" label="Code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" required autofocus class="max-w-48 text-center font-mono text-lg tracking-[0.3em]" />
            <div class="flex flex-wrap gap-3">
                <x-button>Turn on</x-button>
                <x-button href="{{ route('settings') }}" variant="ghost">Cancel</x-button>
            </div>
        </form>
    </x-card>
</x-layouts.app>
