@php
    $resubmit = $book->status === \App\Enums\BookStatus::ChangesRequested;
@endphp

<x-layouts.app :title="'Edit: '.$book->title">
    <x-page-header :title="$resubmit ? 'Make changes' : 'Edit upload'"
        :subtitle="$resubmit ? 'Fix what the reviewer asked for, then send it back for review.' : 'Changes are saved straight away; it stays in the review queue.'"
        :back="route('uploads.show', $book)" back-label="{{ $book->title }}" />

    @if ($changes?->comment)
        <div class="review-note review-note-warning mb-6">
            <x-icon-tile name="triangle-alert" tone="yellow" />
            <div class="min-w-0">
                <h2 class="text-base">What the reviewer asked for</h2>
                <blockquote class="mt-2">{{ $changes->comment }}</blockquote>
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('uploads.update', $book) }}" enctype="multipart/form-data" class="max-w-3xl space-y-6" novalidate>
        @csrf
        @method('PUT')

        @error('upload')
            <x-alert type="error">{{ $message }}</x-alert>
        @enderror

        <x-card class="space-y-4">
            <h2 class="upload-step"><span>1</span> About the book</h2>
            <x-field name="title" label="Title" required maxlength="255" :value="$book->title" />
            <x-field name="author" label="Author or lecturer" required maxlength="150" :value="$book->author" />
            <div class="grid gap-4 sm:grid-cols-2">
                <x-select name="level" label="Level" required :options="$levels" :value="$book->level->value" />
                @if (count($departments) > 1)
                    <x-select name="department_id" label="Department" required :options="$departments" :value="$book->department_id" />
                @else
                    <input type="hidden" name="department_id" value="{{ array_key_first($departments) ?? $book->department_id }}">
                @endif
            </div>
            <div>
                <label for="description" class="field-label">Description <span class="font-normal text-muted">(optional)</span></label>
                <textarea id="description" name="description" rows="3" maxlength="2000" class="field-input field-textarea"
                    @error('description') aria-invalid="true" aria-describedby="description-error" @enderror>{{ old('description', $book->description) }}</textarea>
                @error('description')
                    <p id="description-error" class="field-error">{{ $message }}</p>
                @enderror
            </div>
        </x-card>

        <x-card class="space-y-4">
            <h2 class="upload-step"><span>2</span> Replace files <span class="font-normal text-muted">(optional)</span></h2>
            <div class="flex items-center gap-4">
                <div class="w-16 shrink-0"><x-book-cover :book="$book" /></div>
                <p class="text-sm text-muted">Leave these empty to keep the current file and cover.
                    @if ($book->currentFile?->source === \App\Enums\BookSource::Scan)
                        To replace photos of pages, upload them again as a new book and delete this one.
                    @endif
                </p>
            </div>
            <div>
                <label for="pdf" class="field-label">New PDF</label>
                <input id="pdf" name="pdf" type="file" accept="application/pdf,.pdf" class="field-input field-file"
                    @error('pdf') aria-invalid="true" aria-describedby="pdf-error" @enderror>
                @error('pdf')
                    <p id="pdf-error" class="field-error">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="cover" class="field-label">New cover</label>
                <input id="cover" name="cover" type="file" accept="image/jpeg,image/png,image/webp" class="field-input field-file"
                    @error('cover') aria-invalid="true" aria-describedby="cover-error" @enderror>
                @error('cover')
                    <p id="cover-error" class="field-error">{{ $message }}</p>
                @enderror
            </div>
        </x-card>

        <div class="flex flex-wrap items-center gap-3">
            <x-button :icon="$resubmit ? 'upload' : 'circle-check'">{{ $resubmit ? 'Send back for review' : 'Save changes' }}</x-button>
            <x-button href="{{ route('uploads.show', $book) }}" variant="ghost">Cancel</x-button>
        </div>
    </form>
</x-layouts.app>
