{{-- Reminds signed-in users to add or verify the email used for password resets. --}}
@auth
    @php($user = auth()->user())
    @if (! request()->routeIs('settings') && ! $user->hasVerifiedEmailAddress())
        <x-alert type="info" class="mb-6">
            @if ($user->email)
                Verify <strong>{{ $user->email }}</strong> so you can reset your password by email. Check your inbox, or <a href="{{ route('settings') }}" class="link">resend the link</a>.
            @else
                Add an email address so you can reset your password yourself if you forget it. <a href="{{ route('settings') }}" class="link">Add email</a>
            @endif
        </x-alert>
    @endif
@endauth
