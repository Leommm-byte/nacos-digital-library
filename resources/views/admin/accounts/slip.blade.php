{{-- One account slip: what a student needs for their first login. --}}
<div class="slip">
    <p class="mb-2 text-sm font-bold">{{ config('app.name') }} · your account</p>
    <dl>
        <dt>Name</dt><dd>{{ $slip['name'] }}</dd>
        <dt>Matric number (your username)</dt><dd>{{ $slip['matric'] }}</dd>
        <dt>First password</dt><dd class="slip-password">{{ $slip['password'] }}</dd>
    </dl>
    <p class="text-xs text-muted">Log in at {{ preg_replace('#^https?://#', '', url('/login')) }}. You'll choose your own password straight away.</p>
</div>
