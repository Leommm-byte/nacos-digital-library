<x-layouts.admin title="Account slips">
    <x-page-header title="Account slips" :subtitle="count($batch['slips']).' '.\Illuminate\Support\Str::plural('account', count($batch['slips'])).' created. Print the slips now: the passwords are only shown on this page.'" :back="route('admin.accounts.index')" back-label="Create accounts">
        <x-slot:actions>
            <x-button type="button" icon="printer" data-print>Print slips</x-button>
        </x-slot:actions>
    </x-page-header>

    @if (($batch['invited'] ?? 0) > 0)
        <x-alert type="success" class="no-print mb-6">
            {{ number_format($batch['invited']) }} {{ \Illuminate\Support\Str::plural('student', $batch['invited']) }} with an email will get an invitation instead of a slip. {{ \App\Http\Controllers\Admin\AccountController::sendingTime() }}
            <a href="{{ route('admin.accounts.invitations') }}" class="link font-semibold">See invitations</a>
        </x-alert>
    @endif

    @if ($batch['remaining'] > 0)
        <form method="POST" action="{{ route('admin.accounts.store') }}" class="no-print mb-6">
            @csrf
            @foreach ($batch['classes'] as $class)
                <input type="hidden" name="classes[]" value="{{ $class }}">
            @endforeach
            <input type="hidden" name="department_id" value="{{ $batch['department_id'] }}">
            <input type="hidden" name="send" value="{{ $batch['send'] ?? 'slips' }}">
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
