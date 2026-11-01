<x-layouts.vote>
    <section class="vote-hero animate-enter">
        @include('vote.partials.logo', ['size' => 'lg'])
        <div class="min-w-0">
            <p class="vote-eyebrow">Elections</p>
            <h1 class="vote-hero-title">{{ $organization->name }}</h1>
            <p class="mt-2 max-w-2xl text-muted">
                @if ($voter)
                    Welcome, {{ $voter->firstName() }}. Vote in the open elections below; results are public and update while voting is open.
                @else
                    Register with your matric number to vote. Only students on the department's nominal roll can register. Anyone can follow the results.
                @endif
            </p>
            @unless ($voter)
                <div class="mt-5 flex flex-wrap gap-3">
                    <x-button href="{{ route('vote.register') }}" icon="user-plus">Register to vote</x-button>
                    <x-button href="{{ route('vote.login') }}" variant="secondary" icon="log-in">Log in</x-button>
                </div>
            @endunless
            @if ($voter?->isAdmin())
                <div class="mt-5 flex flex-wrap gap-3">
                    <x-button href="{{ route('vote.admin.elections') }}" variant="secondary" icon="settings">Manage elections</x-button>
                    @if ($drafts > 0)
                        <p class="self-center text-sm text-muted">{{ $drafts }} {{ \Illuminate\Support\Str::plural('draft', $drafts) }} being set up</p>
                    @endif
                </div>
            @endif
        </div>
    </section>

    <div class="mt-10">
        @if ($open->isEmpty())
            <x-empty-state icon="vote" tone="green" title="No election open" text="When voting opens, the election appears here with its ballot and live results. Register now, so you're ready: accounts made after voting opens usually can't vote." />
        @else
            <h2 class="mb-4 text-lg">Open now</h2>
            @include('elections.partials.open-cards', ['show' => fn ($election) => route('vote.elections.show', $election), 'user' => $voter])
        @endif
    </div>

    @if ($past->isNotEmpty())
        <x-section title="Past elections" description="Final results of earlier elections." icon="trophy" tone="yellow" class="mt-12">
            @include('elections.partials.past-list', ['show' => fn ($election) => route('vote.elections.show', $election)])
        </x-section>
    @endif
</x-layouts.vote>
