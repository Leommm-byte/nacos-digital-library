{{-- "Your executives": the first few photos and a link to everyone. --}}
@php($people = $executives['people'])
@php($more = $executives['total'] - $people->count())
@if ($people->isEmpty())
    <p class="mt-3 text-sm text-muted">The current executives will appear here once they're announced.</p>
@else
    <a href="{{ route('executives.index') }}" class="executive-stack mt-4" aria-label="Know your executives: {{ $executives['total'] }} {{ \Illuminate\Support\Str::plural('person', $executives['total']) }}">
        @foreach ($people as $executive)
            <x-candidate-avatar :name="$executive->name" :photo="$executive->photoUrl()" />
        @endforeach
        @if ($more > 0)
            <span class="candidate-avatar executive-stack-more" aria-hidden="true">+{{ $more }}</span>
        @endif
    </a>
    <p class="mt-3 text-sm text-muted">
        {{ $people->first()->position }}: <span class="font-semibold text-fg">{{ $people->first()->name }}</span>@if ($executives['total'] > 1), and {{ $executives['total'] - 1 }} more leading NACOS this session.@endif
    </p>
@endif
<a href="{{ route('executives.index') }}" class="section-link mt-4">Know your executives <x-icon name="arrow-right" /></a>
