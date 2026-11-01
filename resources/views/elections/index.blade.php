<x-layouts.app title="Elections" description="NACOS YabaTech elections: vote, and follow the results live.">
    <x-page-header title="Elections" subtitle="Vote for your NACOS leaders. Results are public and update while voting is open.">
        <x-slot:actions>
            <x-button href="{{ route('executives.index') }}" variant="secondary" icon="users">Know your executives</x-button>
            @can('manage-elections')
                <x-button href="{{ route('elections.manage') }}" variant="secondary" icon="settings">Manage elections</x-button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    @if ($open->isEmpty())
        <x-empty-state icon="vote" tone="green" title="No election active" text="When voting opens, the election will appear here with its ballot and live results." />
    @else
        @include('elections.partials.open-cards', ['show' => fn ($election) => route('elections.show', $election)])
    @endif

    @if ($past->isNotEmpty())
        <x-section title="Past elections" description="Final results of earlier elections." icon="trophy" tone="yellow" class="mt-12">
            @include('elections.partials.past-list', ['show' => fn ($election) => route('elections.show', $election)])
        </x-section>
    @endif
</x-layouts.app>
