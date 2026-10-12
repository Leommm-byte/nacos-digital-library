<x-layouts.admin title="Executives">
    <x-page-header title="Executives" subtitle="Who students see on Know your executives, in this order.">
        <x-slot:actions>
            <x-button href="{{ route('executives.index') }}" variant="secondary" icon="eye">See the page</x-button>
        </x-slot:actions>
    </x-page-header>

    <div class="grid grid-cols-1 gap-8 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start">
        <div class="min-w-0">
            @if ($executives->isEmpty())
                <x-empty-state icon="users" tone="green" title="No executives yet"
                    text="Add each executive with their position and photo, or fill the list from an election's winners." />
            @else
                <ul class="space-y-3">
                    @foreach ($executives as $executive)
                        @php($bag = 'executive-'.$executive->id)
                        <li id="executive-{{ $executive->id }}" class="scroll-mt-24">
                            <details class="executive-edit" @if ($errors->getBag($bag)->any()) open @endif>
                                <summary>
                                    <x-candidate-avatar :name="$executive->name" :photo="$executive->photoUrl()" />
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate font-semibold">{{ $executive->name }}</span>
                                        <span class="block truncate text-sm text-muted">{{ $executive->position }}</span>
                                    </span>
                                    <x-icon name="pencil" class="shrink-0 text-muted" />
                                </summary>
                                <div class="space-y-4 border-t border-border p-4">
                                    @include('admin.executives.form', [
                                        'executive' => $executive,
                                        'action' => route('admin.executives.update', $executive),
                                        'method' => 'PUT',
                                        'bag' => $bag,
                                        'prefix' => 'executive-'.$executive->id,
                                    ])
                                    <div class="flex flex-wrap items-center gap-2 border-t border-border pt-4">
                                        <form method="POST" action="{{ route('admin.executives.move', $executive) }}">
                                            @csrf
                                            @method('PUT')
                                            <input type="hidden" name="direction" value="up">
                                            <x-button size="sm" variant="ghost" icon="arrow-up" :disabled="$loop->first">Move up</x-button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.executives.move', $executive) }}">
                                            @csrf
                                            @method('PUT')
                                            <input type="hidden" name="direction" value="down">
                                            <x-button size="sm" variant="ghost" icon="arrow-down" :disabled="$loop->last">Move down</x-button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.executives.destroy', $executive) }}" class="ml-auto" data-confirm="Remove {{ $executive->name }} from the executives?">
                                            @csrf
                                            @method('DELETE')
                                            <x-button size="sm" variant="ghost" icon="trash-2" class="text-danger">Remove</x-button>
                                        </form>
                                    </div>
                                </div>
                            </details>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <aside class="space-y-4">
            <x-card class="space-y-4" id="add">
                <div class="flex items-center gap-3">
                    <x-icon-tile name="user-plus" tone="green" size="sm" />
                    <h2 class="text-base">Add an executive</h2>
                </div>
                @if ($executives->count() >= $max)
                    <p class="text-sm text-muted">The list is full ({{ $max }}). Remove someone first.</p>
                @else
                    @include('admin.executives.form', [
                        'executive' => null,
                        'action' => route('admin.executives.store'),
                        'method' => 'POST',
                        'bag' => 'add',
                        'prefix' => 'add',
                    ])
                @endif
            </x-card>

            @if ($elections->isNotEmpty())
                <x-card class="space-y-4">
                    <div class="flex items-center gap-3">
                        <x-icon-tile name="trophy" tone="yellow" size="sm" />
                        <h2 class="text-base">From an election</h2>
                    </div>
                    <p class="text-sm text-muted">Replace the list with a closed election's winners, one per position, with their photos.</p>
                    @error('election', 'fill')
                        <x-alert type="warning">{{ $message }}</x-alert>
                    @enderror
                    <ul class="space-y-2">
                        @foreach ($elections as $election)
                            <li>
                                <form method="POST" action="{{ route('admin.executives.from-election', $election) }}"
                                    data-confirm="Replace the executives with the winners of {{ $election->title }}?">
                                    @csrf
                                    <x-button size="sm" variant="secondary" icon="trophy" class="w-full">{{ $election->title }}</x-button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                </x-card>
            @endif
        </aside>
    </div>
</x-layouts.admin>
