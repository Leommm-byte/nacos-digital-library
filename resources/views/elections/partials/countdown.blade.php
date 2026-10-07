{{-- Time left until $ends; elections.js keeps it ticking. --}}
@php
    $left = max(0, now()->diffInSeconds($ends, false));
    $days = intdiv((int) $left, 86400);
    $clock = sprintf('%02d:%02d:%02d', intdiv((int) $left % 86400, 3600), intdiv((int) $left % 3600, 60), (int) $left % 60);
@endphp
<time class="countdown" datetime="{{ $ends->toIso8601String() }}" data-countdown data-now="{{ now()->getTimestampMs() }}" @isset($reload) data-reload @endisset role="timer">
    <x-icon name="timer" />
    <span data-countdown-text>{{ $left > 0 ? ($days > 0 ? $days.' '.\Illuminate\Support\Str::plural('day', $days).' ' : '').$clock.' left' : 'Voting has ended' }}</span>
</time>
