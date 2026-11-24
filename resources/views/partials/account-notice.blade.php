{{-- Signed-in reminders. While a NACOS election that needs class-email
     verification is being set up or open, show the student exactly where
     they stand (so nobody has to guess whether they can vote); otherwise
     nudge them to add/verify the email used for password resets. --}}
@auth
    @php($user = auth()->user())
    @if (! request()->routeIs('settings') && ! request()->routeIs('verify-account*'))
        @php($election = $user instanceof \App\Models\User ? \App\Models\Election::rollEmailNudgeElection() : null)
        @if ($election !== null)
            @php($status = $election->voterStatus($user))
            @if ($status === 'ready')
                <x-alert type="success" class="mb-6">
                    <strong>You're all set to vote.</strong> Your account is confirmed and you're on the list for the NACOS election. There's nothing else you need to do.
                </x-alert>
            @elseif ($status === 'verify')
                <x-alert type="warning" class="mb-6">
                    <strong>Confirm your account so you can vote.</strong> Every voter confirms before the NACOS election. It also makes sure you're the only one with access. <a href="{{ route('verify-account') }}" class="link">Verify my account</a> and we'll send a one-time link to the email on record for you.
                </x-alert>
            @elseif ($status === 'hold')
                <x-alert type="warning" class="mb-6">
                    <strong>Your account is on hold for voting.</strong> Please see a NACOS admin with your student ID so they can confirm it's you. Once they've checked, you can vote straight away.
                </x-alert>
            @else
                <x-alert type="info" class="mb-6">
                    <strong>You can't vote in the NACOS election.</strong> {{ $election->ineligibilityReason($user) }}
                </x-alert>
            @endif
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
