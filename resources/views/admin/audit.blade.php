@php
    $zone = config('app.display_timezone');
    $actionOptions = ['' => 'Every action'] + $actions;
    // Meta keys worth showing, in plain words.
    $metaLabels = ['title' => 'Title', 'name' => 'Name', 'class' => 'Class', 'from' => 'From', 'to' => 'To', 'count' => 'Count', 'total' => 'Total', 'added' => 'Added', 'removed' => 'Removed', 'file' => 'File', 'hours' => 'Hours', 'position' => 'Position', 'candidate' => 'Candidate', 'automatic' => 'Automatic'];
@endphp

<x-layouts.admin title="Audit log">
    <x-page-header title="Audit log" subtitle="Who did what, and when. Votes are recorded as having happened, never how anyone voted." />

    <form method="GET" action="{{ route('admin.audit') }}" class="filter-bar mb-6" data-autosubmit>
        <x-select name="action" label="Action" :options="$actionOptions" :value="$filters['action'] ?? ''" />
        <div class="filter-search">
            <label for="audit-who" class="field-label">Who</label>
            <input id="audit-who" name="who" type="search" value="{{ $filters['who'] ?? '' }}" placeholder="Name or matric number" class="field-input">
        </div>
        <x-field name="from" label="From" type="date" :value="$filters['from'] ?? ''" />
        <x-field name="to" label="To" type="date" :value="$filters['to'] ?? ''" />
        <div class="filter-actions"><x-button variant="secondary" icon="search">Filter</x-button></div>
    </form>

    @if ($entries->isEmpty())
        <x-empty-state icon="scroll-text" title="Nothing found" text="No entries match these filters." />
    @else
        <div class="table-scroll">
            <table class="admin-table">
                <thead>
                    <tr><th>When</th><th>Who</th><th>What</th><th>Details</th></tr>
                </thead>
                <tbody>
                    @foreach ($entries as $entry)
                        <tr>
                            <td class="whitespace-nowrap">{{ $entry->created_at->timezone($zone)->format('j M Y') }}<span class="block text-xs text-muted">{{ $entry->created_at->timezone($zone)->format('g:i:s a') }}</span></td>
                            <td>
                                @if ($entry->user)
                                    <a href="{{ route('admin.users.show', $entry->user) }}" class="link">{{ $entry->user->fullname }}</a>
                                    <span class="block text-xs text-muted">{{ $entry->user->matric_number }}</span>
                                @else
                                    <span class="text-muted">System</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap">{{ \App\Support\AuditActions::label($entry->action) }}</td>
                            <td class="audit-meta">
                                @foreach ($entry->meta ?? [] as $key => $value)
                                    @if (isset($metaLabels[$key]) && is_scalar($value))
                                        <span class="block">{{ $metaLabels[$key] }}: {{ is_bool($value) ? ($value ? 'yes' : 'no') : $value }}</span>
                                    @endif
                                @endforeach
                                @if ($entry->ip_address)
                                    <span class="block">IP {{ $entry->ip_address }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{ $entries->links('partials.pagination') }}
    @endif
</x-layouts.admin>
