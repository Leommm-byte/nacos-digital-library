@php
    $editing = $announcement->exists;
    $zone = config('app.display_timezone');
    $audience = old('audience', $announcement->schoolClass()?->key() ?? '');
@endphp

<x-layouts.app :title="$editing ? 'Edit announcement' : 'New announcement'">
    <x-page-header :title="$editing ? 'Edit announcement' : 'New announcement'"
        :subtitle="$class ? 'For '.$class->label().': your class and admins see it.' : 'For everyone, or for one class.'"
        :back="$editing ? route('announcements.show', $announcement) : route('announcements.manage')" :back-label="$editing ? $announcement->title : 'Manage announcements'" />

    <form method="POST" action="{{ $editing ? route('announcements.update', $announcement) : route('announcements.store') }}" class="max-w-3xl space-y-6" novalidate>
        @csrf
        @if ($editing)
            @method('PUT')
        @endif

        <x-card class="space-y-4">
            @if ($audiences !== null)
                <x-select name="audience" label="Who sees it" :options="$audiences" :value="$audience" />
            @else
                <p class="text-sm"><span class="field-label inline">Who sees it:</span> {{ $class?->label() }} and admins.</p>
            @endif

            <x-field name="title" label="Title" required maxlength="200" :value="$announcement->title" />

            <div data-markdown-editor>
                <label for="body" class="field-label">Message</label>
                {{-- markdown.js wraps the selection in Markdown; typing it works too. --}}
                <div class="editor-toolbar" role="toolbar" aria-label="Formatting" aria-controls="body" hidden data-editor-toolbar>
                    <button type="button" class="editor-button" data-format="bold" aria-label="Bold" title="Bold"><strong>B</strong></button>
                    <button type="button" class="editor-button" data-format="italic" aria-label="Italic" title="Italic"><em>I</em></button>
                    <button type="button" class="editor-button" data-format="heading" aria-label="Heading" title="Heading">H</button>
                    <button type="button" class="editor-button" data-format="bullets" aria-label="Bulleted list" title="Bulleted list"><x-icon name="list-checks" /></button>
                    <button type="button" class="editor-button" data-format="numbers" aria-label="Numbered list" title="Numbered list">1.</button>
                    <button type="button" class="editor-button" data-format="link" aria-label="Link" title="Link">Link</button>
                </div>
                <textarea id="body" name="body" rows="12" maxlength="{{ $bodyMax }}" required class="field-input field-textarea editor-textarea"
                    aria-describedby="body-hint @error('body') body-error @enderror" @error('body') aria-invalid="true" @enderror>{{ old('body', $announcement->body) }}</textarea>
                <p id="body-hint" class="mt-1.5 text-sm text-muted">
                    A blank line starts a new paragraph. <code>**bold**</code>, <code>*italics*</code>, <code>- </code> for a list, <code>1. </code> for a numbered list, <code>## </code> for a heading, <code>[words](https://link)</code> for a link.
                    The list and home page show the first lines, with "Read more".
                </p>
                @error('body')
                    <p id="body-error" class="field-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="starts_on" label="First day (optional)" type="date" :value="$announcement->starts_at?->timezone($zone)->format('Y-m-d')" hint="Leave empty to show it now." />
                <x-field name="ends_on" label="Last day (optional)" type="date" :value="$announcement->ends_at?->timezone($zone)->format('Y-m-d')" hint="Shown until the end of this day." />
            </div>
            <label class="flex items-center gap-3">
                <input type="checkbox" name="is_published" value="1" class="size-4 accent-[var(--primary)]" @checked(old('is_published', $announcement->is_published ?? true))>
                <span>Published <span class="text-sm text-muted">(untick to keep it as a draft)</span></span>
            </label>
        </x-card>

        <div class="flex flex-wrap items-center gap-3">
            <x-button icon="circle-check">{{ $editing ? 'Save changes' : 'Post announcement' }}</x-button>
            <x-button :href="$editing ? route('announcements.show', $announcement) : route('announcements.manage')" variant="ghost">Cancel</x-button>
        </div>
    </form>

    @if ($editing)
        <form method="POST" action="{{ route('announcements.destroy', $announcement) }}" class="mt-8" data-confirm="Delete this announcement?">
            @csrf
            @method('DELETE')
            <button type="submit" class="link text-sm text-danger">Delete this announcement</button>
        </form>
    @endif
</x-layouts.app>
