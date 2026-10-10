@php
    $charts = [
        ['Books added to the library', 'Approved books per month, last 12 months.', $approvedByMonth, 'library-big', 'green'],
        ['Uploads', 'Everything uploaded per month, whatever happened to it.', $uploadsByMonth, 'upload', 'blue'],
        ['Library by department', 'Approved books.', $byDepartment, 'building-2', 'violet'],
        ['Library by level', 'Approved books.', $byLevel, 'book-open', 'yellow'],
    ];
@endphp

<x-layouts.admin title="Reports">
    <x-page-header title="Reports" subtitle="The library and accounts in numbers." />

    <div class="grid gap-8 xl:grid-cols-2">
        @foreach ($charts as [$title, $description, $data, $icon, $tone])
            @php($max = max(1, max($data ?: [0])))
            <x-section :title="$title" :description="$description" :icon="$icon" :tone="$tone">
                <x-card>
                    @if (array_sum($data) === 0)
                        <p class="text-sm text-muted">Nothing yet.</p>
                    @else
                        <ul class="bar-list">
                            @foreach ($data as $label => $count)
                                <li title="{{ $label }}: {{ number_format($count) }}">
                                    <span class="truncate text-muted">{{ $label }}</span>
                                    <progress class="result-bar result-bar-strong m-0" max="{{ $max }}" value="{{ $count }}" aria-label="{{ $label }}: {{ $count }}">{{ $count }}</progress>
                                    <span class="num">{{ number_format($count) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-card>
            </x-section>
        @endforeach
    </div>

    <x-section title="Accounts by class" description="Active accounts, by the class students chose or an admin set." icon="users" tone="blue" class="mt-10">
        <div class="table-scroll" tabindex="0" role="region" aria-label="Table, scrolls sideways">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Programme</th>
                        @foreach (\App\Enums\Level::cases() as $level)
                            <th class="num">{{ $level->label() }}</th>
                        @endforeach
                        <th class="num">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($accounts as $programme => $levels)
                        <tr>
                            <td class="font-semibold">{{ $programme }}</td>
                            @foreach ($levels as $count)
                                <td class="num">{{ $count ? number_format($count) : '–' }}</td>
                            @endforeach
                            <td class="num font-semibold">{{ number_format(array_sum($levels)) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-section>
</x-layouts.admin>
