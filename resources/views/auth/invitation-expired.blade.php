<x-layouts.guest title="This link has expired">
    <h1 class="text-2xl">This link has expired</h1>
    <p class="mt-2 text-muted">Invitation links work once, for {{ config('mail.invitations.expire_days') }} days. If you've already chosen your password, log in with your matric number.</p>
    <p class="mt-2 text-muted">Still need one? Enter your matric number on "Forgot password" and we'll email a new link, or ask a NACOS admin.</p>

    <div class="mt-6 grid gap-3">
        <x-button href="{{ route('login') }}" icon="log-in">Log in</x-button>
        <x-button href="{{ route('password.request') }}" variant="secondary">Forgot password</x-button>
    </div>
</x-layouts.guest>
