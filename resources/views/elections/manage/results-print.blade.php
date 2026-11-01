{{-- One page of results to print or save as PDF (Print, then "Save as PDF"). --}}
@php
    $zone = config('app.display_timezone');
    $final = (bool) ($results['final'] ?? false);
    $owner = $desk->organization?->name ?? config('app.name');
    $percent = fn ($value) => \Illuminate\Support\Number::format((float) $value, maxPrecision: 1);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="light" data-theme-fixed>
<head>
    @include('partials.head', ['title' => $election->title.' results', 'description' => null, 'siteName' => $owner])
    <meta name="robots" content="noindex">
    @if ($desk->isDepartment())
        @vite('resources/css/vote.css')
    @endif
    {{-- Always light: a printout or PDF, whatever the viewer's theme. --}}
    <script nonce="{{ Vite::cspNonce() }}">document.documentElement.dataset.theme = 'light';</script>
</head>
<body @class(['bg-surface', \App\Support\Departments\Colours::bodyClass($desk->organization?->colour)])>
    <main class="results-print container-page py-8">
        <div class="no-print mb-6 flex flex-wrap gap-3">
            <x-button type="button" icon="printer" data-print>Print or save as PDF</x-button>
            <x-button href="{{ $desk->route('.show', $election) }}" variant="secondary" icon="arrow-left">Back</x-button>
        </div>

        <p class="text-sm font-semibold text-muted">{{ $owner }}</p>
        <h1 class="mt-1 text-2xl">{{ $election->title }}</h1>
        <p class="mt-1 text-sm text-muted">
            {{ $final ? 'Final results' : 'Live standings: voting is still open' }} ·
            Voting {{ $election->starts_at?->timezone($zone)->format('j M Y, g:i a') }} to {{ ($election->closed_at ?? $election->ends_at)?->timezone($zone)->format('j M Y, g:i a') }}
        </p>
        <p class="mt-3">
            <strong>{{ number_format($results['ballots'] ?? 0) }}</strong> of {{ number_format($results['electorate'] ?? 0) }} eligible voted ({{ $percent($results['turnout'] ?? 0) }}%). {{ $election->eligibilitySummary() }}.
        </p>

        @foreach ($results['positions'] ?? [] as $position)
            <section class="mt-6">
                <h2 class="text-lg">{{ $position['title'] }}</h2>
                <table class="admin-table mt-2">
                    <thead><tr><th>Candidate</th><th class="text-right">Votes</th><th class="text-right">%</th><th>Result</th></tr></thead>
                    <tbody>
                        @foreach ($position['candidates'] as $candidate)
                            <tr>
                                <td @class(['font-semibold' => $candidate['leading']])>{{ $candidate['name'] }}</td>
                                <td class="text-right tabular-nums">{{ number_format($candidate['votes']) }}</td>
                                <td class="text-right tabular-nums">{{ $percent($candidate['percent']) }}</td>
                                <td>{{ $candidate['tied'] ? 'Tied' : ($candidate['leading'] ? ($final ? 'Winner' : 'Leading') : '') }}</td>
                            </tr>
                        @endforeach
                        <tr><td class="text-muted">No vote</td><td class="text-right tabular-nums text-muted">{{ number_format($position['skipped']) }}</td><td></td><td></td></tr>
                    </tbody>
                </table>
            </section>
        @endforeach

        <p class="mt-8 text-xs text-muted">
            Printed {{ now()->timezone($zone)->format('j M Y, g:i a') }}. Secret ballot: a record that each student voted is kept, never who they chose.
            @if ($desk->isDepartment()) Powered by NACOS YabaTech. @endif
        </p>
    </main>
</body>
</html>
