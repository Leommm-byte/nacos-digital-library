{{-- Signed-in reminders: confirm your account so you can vote (while an
     election needs it), otherwise add/verify the email used for resets. --}}
@auth
    @php($user = auth()->user())
    @if (! request()->routeIs('settings') && ! request()->routeIs('verify-account*'))
        @if ($user instanceof \App\Models\User && $user->awaitingRollEmailVerification() && \App\Models\Election::rollEmailElectionActive())
            <x-alert type="warning" class="mb-6">
                <strong>Confirm your account so you can vote.</strong> Every voter has to confirm before the NACOS election. <a href="{{ route('verify-account') }}" class="link">Verify my account</a> and we'll send a one-time link to the email on record for you.
            </x-alert>
        @elseif (! $user->hasVerifiedEmailAddress())
            <x-alert type="info" class="mb-6">
                @if ($user->email)
                    Verify <strong>{{ $user->email }}</strong> so you can reset your password by email. Check your inbox, or <a href="{{ route('settings') }}" class="link">resend the link</a>.
                @else
                    Add an email address so you can reset your password yourself if you forget it. <a href="{{ route('settings') }}" class="link">Add email</a>
                @endif
            </x-alert>
        @endif
    @endif
@endauth
