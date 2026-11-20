{{--
    Turnout by class ($turnout from TurnoutByClass): who could vote and how
    many did, class by class. Only for the election's admins; it is built
    from the record that someone voted, never from the ballots.
--}}
@php
    $percent = fn ($value) => \Illuminate\Support\Number::format((float) $value, maxPrecision: 1);
    $holds = ! $desk->isDepartment();
@endphp
<section aria-labelledby="turnout-class-heading" class="space-y-3">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            <x-icon-tile name="users" tone="blue" size="sm" />
            <h2 id="turnout-class-heading" class="text-lg">Turnout by class</h2>
        </div>
        <x-button href="{{ $desk->route('.turnout.csv', $election) }}" variant="secondary" size="sm" icon="download">Turnout (CSV)</x-button>
    </div>
    <p class="text-sm text-muted">How many in each class could vote and how many did. It shows only that someone voted, never who they chose.@if ($election->isAcceptingVotes()) Reload the page for the latest numbers.@endif</p>

    @if ($turnout === [])
        <p class="text-sm text-muted">Nobody can vote in this election yet.</p>
    @else
        <div class="table-scroll" tabindex="0" role="region" aria-label="Turnout by class, scrolls sideways">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Class</th>
                        <th class="text-right">Could vote</th>
                        <th class="text-right">Voted</th>
                        <th class="text-right">Turnout</th>
                        @if ($holds)<th class="text-right">On hold</th>@endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($turnout as $row)
                        <tr>
                            <td>{{ $row['label'] }}</td>
                            <td class="text-right tabular-nums">{{ number_format($row['eligible']) }}</td>
                            <td class="text-right tabular-nums">{{ number_format($row['voted']) }}</td>
                            <td class="text-right">
                                <span class="inline-flex items-center gap-2">
                                    <progress class="result-bar m-0 w-20" max="100" value="{{ $row['turnout'] }}" aria-hidden="true">{{ $row['turnout'] }}%</progress>
                                    <span class="w-12 tabular-nums">{{ $percent($row['turnout']) }}%</span>
                                </span>
                            </td>
                            @if ($holds)<td class="text-right tabular-nums text-muted">{{ number_format($row['held']) }}</td>@endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
