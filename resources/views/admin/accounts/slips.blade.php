<x-layouts.admin title="Account slips">
    <x-page-header title="Account slips" :subtitle="count($batch['slips']).' '.\Illuminate\Support\Str::plural('account', count($batch['slips'])).' created. Print the slips now: the passwords are only shown on this page.'" :back="route('admin.accounts.index')" back-label="Create accounts">
        <x-slot:actions>
            <x-button type="button" icon="printer" data-print>Print slips</x-button>
        </x-slot:actions>
    </x-page-header>

    @if ($batch['remaining'] > 0)
        <form method="POST" action="{{ route('admin.accounts.store') }}" class="no-print mb-6">
            @csrf
            @foreach ($batch['classes'] as $class)
                <input type="hidden" name="classes[]" value="{{ $class }}">
            @endforeach
            <input type="hidden" name="department_id" value="{{ $batch['department_id'] }}">
            <x-alert type="info">
                {{ number_format($batch['remaining']) }} more in these classes still need an account.
                <button type="submit" class="link font-semibold">Create the next batch</button> (after printing these).
            </x-alert>
        </form>
    @endif

    <div class="slips">
        @foreach ($batch['slips'] as $slip)
            @include('admin.accounts.slip', ['slip' => $slip])
        @endforeach
    </div>
</x-layouts.admin>
