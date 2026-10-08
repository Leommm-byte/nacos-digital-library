<x-layouts.admin title="Settings">
    <x-page-header title="Settings" subtitle="Saved in the database and used straight away." />

    <div class="settings">
        <section class="settings-row" aria-labelledby="site-heading">
            <div class="settings-label">
                <x-icon-tile name="settings" tone="green" size="sm" />
                <div>
                    <h2 id="site-heading">Site and uploads</h2>
                    <p>The name in page titles and emails, the current session, upload limits for students (reviewers and admins have none) and the assistant's daily AI answers.</p>
                </div>
            </div>
            <x-card>
                <form method="POST" action="{{ route('admin.settings.update') }}" class="space-y-4" novalidate>
                    @csrf
                    @method('PUT')
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-field name="site_name" label="Site name" required maxlength="60" :value="$settings['site_name']" />
                        <x-field name="academic_session" label="Academic session" placeholder="2026/2027" :value="$settings['academic_session']" hint="Shown on the admin dashboard." />
                    </div>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <x-field name="pdf_max_mb" label="Largest PDF (MB)" type="number" min="1" max="50" required :value="$settings['pdf_max_mb']" />
                        <x-field name="uploads_per_day" label="Uploads per day" type="number" min="1" max="200" required :value="$settings['uploads_per_day']" />
                        <x-field name="uploads_pending" label="Waiting for review" type="number" min="1" max="500" required :value="$settings['uploads_pending']" hint="Most uploads one student can have waiting." />
                    </div>
                    <p class="text-sm text-muted">Students upload PDFs or photos of pages (JPG, PNG or WebP); other file types are always refused.</p>
                    <div class="border-t border-border pt-4">
                        <div class="grid gap-4 sm:grid-cols-3">
                            <x-field name="assistant_daily_limit" label="Smart answers per day" type="number" min="0" max="200" :value="$settings['assistant_daily_limit']" hint="Per student. 0 turns AI answers off." />
                        </div>
                        <p class="mt-2 text-sm text-muted">
                            @if ($aiKey)
                                The assistant uses AI (Claude) for each student's first answers of the day, then quick keyword answers. Each AI answer costs a little; this limit caps the bill.
                            @else
                                No Anthropic API key is set, so the assistant gives quick keyword answers only, at no cost. Add <code>ANTHROPIC_API_KEY</code> to the server's .env to turn on AI answers.
                            @endif
                        </p>
                    </div>
                    <x-button icon="circle-check">Save settings</x-button>
                </form>
            </x-card>
        </section>

        <section class="settings-row" aria-labelledby="departments-heading">
            <div class="settings-label">
                <x-icon-tile name="building-2" tone="blue" size="sm" />
                <div>
                    <h2 id="departments-heading">Departments</h2>
                    <p>Open departments can be chosen at signup and have books in the library. Closing one hides its books; nothing is deleted.</p>
                </div>
            </div>
            <x-card>
                @error('department')
                    <x-alert type="error" class="mb-4">{{ $message }}</x-alert>
                @enderror
                <ul class="divide-y divide-border">
                    @foreach ($departments as $department)
                        <li class="flex items-center gap-3 py-3 first:pt-0 last:pb-0">
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold">{{ $department->name }}</p>
                                <p class="text-xs text-muted">{{ number_format($department->users_count) }} {{ \Illuminate\Support\Str::plural('account', $department->users_count) }}</p>
                            </div>
                            <x-badge :variant="$department->is_active ? 'primary' : 'neutral'">{{ $department->is_active ? 'Open' : 'Closed' }}</x-badge>
                            <form method="POST" action="{{ route('admin.settings.department', $department) }}"
                                data-confirm="{{ $department->is_active ? 'Close '.$department->name.'? Its books leave the library until it opens again.' : 'Open '.$department->name.'?' }}">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="open" value="{{ $department->is_active ? '0' : '1' }}">
                                <x-button size="sm" variant="secondary">{{ $department->is_active ? 'Close' : 'Open' }}</x-button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            </x-card>
        </section>
    </div>
</x-layouts.admin>
