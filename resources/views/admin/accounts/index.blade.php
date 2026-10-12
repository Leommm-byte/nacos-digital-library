@php($totalMissing = $classes->sum(fn ($class) => $class['missing'] - $class['noName']))

<x-layouts.admin title="Create accounts">
    <x-page-header title="Create accounts" subtitle="Accounts for students on the nominal roll who don't have one yet. Email them an invitation to choose their own password, or print slips with a random first password.">
        <x-slot:actions>
            <x-button href="{{ route('admin.accounts.invitations') }}" variant="secondary" icon="mail">Invitations @if ($unused > 0)<span class="review-tab-count">{{ number_format($unused) }}</span>@endif</x-button>
        </x-slot:actions>
    </x-page-header>

    @if ($classes->isEmpty())
        <x-empty-state icon="clipboard-list" tone="green" title="The nominal roll is empty" text="Upload the class lists first; accounts are created from them.">
            <x-button href="{{ route('roll.index') }}" icon="upload">Upload class lists</x-button>
        </x-empty-state>
    @else
        <form method="POST" action="{{ route('admin.accounts.store') }}" class="space-y-6" novalidate>
            @csrf
            @error('classes')
                <x-alert type="error">{{ $message }}</x-alert>
            @enderror

            <div class="table-scroll" tabindex="0" role="region" aria-label="Table, scrolls sideways">
                <table class="admin-table">
                    <thead>
                        <tr><th class="w-10"><span class="sr-only">Choose</span></th><th>Class</th><th class="num">On the roll</th><th class="num">Need an account</th><th class="num">With an email</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($classes as $class)
                            @php($ready = $class['missing'] - $class['noName'])
                            <tr>
                                <td><input type="checkbox" name="classes[]" value="{{ $class['key'] }}" id="class-{{ $loop->index }}" class="size-4 accent-[var(--primary)]" @checked(in_array($class['key'], (array) old('classes', []), true)) @disabled($ready === 0)></td>
                                <td><label for="class-{{ $loop->index }}" class="font-semibold">{{ $class['label'] }}</label>
                                    @if ($class['noName'] > 0)
                                        <span class="block text-xs text-muted">{{ $class['noName'] }} without a name on the roll (skipped)</span>
                                    @endif
                                </td>
                                <td class="num">{{ number_format($class['total']) }}</td>
                                <td class="num">@if ($ready > 0)<strong>{{ number_format($ready) }}</strong>@else <span class="text-muted">All done</span>@endif</td>
                                <td class="num">@if ($ready > 0){{ number_format($class['withEmail']) }}@else <span class="text-muted">–</span>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-card class="flex flex-wrap items-end gap-4">
                <div class="min-w-48 flex-1">
                    <x-select name="department_id" label="Department" :options="$departments" :value="old('department_id', array_key_first($departments))" />
                </div>
                <div class="flex flex-wrap gap-3">
                    <x-button name="send" value="email" icon="mail" :disabled="$totalMissing === 0">Create accounts and email invitations</x-button>
                    <x-button name="send" value="slips" variant="secondary" icon="printer" :disabled="$totalMissing === 0">Create accounts and print slips</x-button>
                </div>
                <ul class="w-full list-disc space-y-1 pl-5 text-sm text-muted">
                    <li><strong class="text-fg">Email invitations:</strong> students with an email on the roll get a link to choose their own password (it works for {{ config('mail.invitations.expire_days') }} days). Those without one get a slip. About {{ config('mail.invitations.per_hour') }} emails go out an hour.</li>
                    <li><strong class="text-fg">Print slips:</strong> everyone gets a slip with a random first password, shown once on the next page.</li>
                    <li>Up to {{ \App\Http\Controllers\Admin\AccountController::BATCH }} accounts at a time. Check the emails on the class list first: an invitation goes to whatever address is there.</li>
                </ul>
            </x-card>
        </form>
    @endif
</x-layouts.admin>
