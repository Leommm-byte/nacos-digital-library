@php
    $editing = $announcement->exists;
    $zone = config('app.display_timezone');
@endphp

<x-layouts.app :title="$editing ? 'Edit announcement' : 'New announcement'">
    <x-page-header :title="$editing ? 'Edit announcement' : 'New announcement'" subtitle="Shown on every student's home page while it's live." :back="route('announcements.manage')" back-label="Manage announcements" />

    <form method="POST" action="{{ $editing ? route('announcements.update', $announcement) : route('announcements.store') }}" class="max-w-2xl space-y-6" novalidate>
        @csrf
        @if ($editing)
            @method('PUT')
        @endif

        <x-card class="space-y-4">
            <x-field name="title" label="Title" required maxlength="200" :value="$announcement->title" />
            <div>
                <label for="body" class="field-label">Message</label>
                <textarea id="body" name="body" rows="6" maxlength="5000" required class="field-input field-textarea"
                    @error('body') aria-invalid="true" aria-describedby="body-error" @enderror>{{ old('body', $announcement->body) }}</textarea>
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
            <x-button href="{{ route('announcements.manage') }}" variant="ghost">Cancel</x-button>
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
