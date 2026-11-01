{{-- A sign-in slip for a department's election space. Expects $organization and $slip (name, login, password). --}}
<div class="slip">
    <p class="mb-2 text-sm font-bold">{{ $organization->name }} elections · your account</p>
    <dl>
        <dt>Name</dt><dd>{{ $slip['name'] }}</dd>
        <dt>{{ str_contains($slip['login'], '@') ? 'Email (your username)' : 'Matric number (your username)' }}</dt><dd>{{ $slip['login'] }}</dd>
        <dt>Temporary password</dt><dd class="slip-password">{{ $slip['password'] }}</dd>
    </dl>
    <p class="text-xs text-muted">Log in at {{ preg_replace('#^https?://#', '', route('vote.login', $organization)) }}. You'll choose your own password straight away.</p>
</div>
