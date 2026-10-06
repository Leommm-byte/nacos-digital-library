@php
    $type = old('type', 'pdf');
    $pagesError = $errors->first('pages') ?: collect($errors->getMessages())->first(fn ($messages, $key) => str_starts_with($key, 'pages.'))[0] ?? null;
@endphp

<x-layouts.app title="Share a book" description="Upload a PDF or photos of a book's pages for the NACOS library.">
    <x-page-header title="Share a book" subtitle="Upload a PDF, or photos of the pages. Reviewers check every upload before it appears in the library.">
        <x-slot:actions>
            <x-button href="{{ route('uploads.index') }}" variant="secondary" icon="upload">Your uploads</x-button>
        </x-slot:actions>
    </x-page-header>

    @if ($blocked)
        <x-empty-state icon="clock" tone="yellow" title="You can't upload right now" :text="$blocked">
            <x-button href="{{ route('uploads.index') }}" variant="secondary">See your uploads</x-button>
        </x-empty-state>
    @else
        <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-start">
            <form method="POST" action="{{ route('uploads.store') }}" enctype="multipart/form-data" novalidate
                class="upload-form space-y-6" data-upload
                data-pdf-max="{{ config('uploads.pdf_max_kb') * 1024 }}"
                data-page-max="{{ config('uploads.page_max_kb') * 1024 }}"
                data-cover-max="{{ config('uploads.cover_max_kb') * 1024 }}"
                data-max-pages="{{ config('uploads.max_pages') }}"
                data-page-pixels="{{ config('uploads.page_max_pixels') }}"
                data-assets="{{ asset('build') }}/">
                @csrf

                @error('upload')
                    <x-alert type="error">{{ $message }}</x-alert>
                @enderror

                <x-card class="space-y-5">
                    <fieldset>
                        <legend class="upload-step"><span>1</span> What are you sharing?</legend>
                        <div class="upload-types mt-4">
                            <label class="upload-type">
                                <input type="radio" name="type" value="pdf" class="sr-only" @checked($type === 'pdf') data-upload-type>
                                <x-icon-tile name="file-text" tone="green" />
                                <span><strong>A PDF file</strong><small>Up to {{ config('uploads.pdf_max_kb') / 1024 }} MB</small></span>
                            </label>
                            <label class="upload-type">
                                <input type="radio" name="type" value="scan" class="sr-only" @checked($type === 'scan') data-upload-type>
                                <x-icon-tile name="camera" tone="blue" />
                                <span><strong>Photos of pages</strong><small>Up to {{ config('uploads.max_pages') }} pages</small></span>
                            </label>
                        </div>
                    </fieldset>

                    <div class="upload-panel upload-panel-pdf">
                        <label for="pdf" class="dropzone" data-dropzone>
                            <input id="pdf" name="pdf" type="file" accept="application/pdf,.pdf" class="sr-only" data-upload-pdf
                                @error('pdf') aria-invalid="true" aria-describedby="pdf-error" @enderror>
                            <x-icon-tile name="file-up" tone="green" size="lg" />
                            <span class="dropzone-title">Choose a PDF <span class="hidden sm:inline">or drop it here</span></span>
                            <span class="dropzone-hint">One file, up to {{ config('uploads.pdf_max_kb') / 1024 }} MB</span>
                        </label>
                        <ul class="upload-files" data-upload-pdf-list aria-live="polite"></ul>
                        @error('pdf')
                            <p id="pdf-error" class="field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="upload-panel upload-panel-scan">
                        <label for="pages" class="dropzone" data-dropzone>
                            <input id="pages" name="pages[]" type="file" accept="image/jpeg,image/png,image/webp" multiple class="sr-only" data-upload-pages
                                @if ($pagesError) aria-invalid="true" aria-describedby="pages-error" @endif>
                            <x-icon-tile name="camera" tone="blue" size="lg" />
                            <span class="dropzone-title">Add photos of the pages</span>
                            <span class="dropzone-hint">In page order · JPG, PNG or WebP · up to {{ config('uploads.max_pages') }} pages</span>
                        </label>
                        <ol class="upload-pages" data-upload-page-list aria-label="Pages"></ol>
                        @if ($pagesError)
                            <p id="pages-error" class="field-error">{{ $pagesError }}</p>
                        @endif
                    </div>
                </x-card>

                <x-card class="space-y-4">
                    <h2 class="upload-step"><span>2</span> About the book</h2>
                    <x-field name="title" label="Title" required maxlength="255" autocomplete="off" />
                    <x-field name="author" label="Author or lecturer" required maxlength="150" autocomplete="off" />
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-select name="level" label="Level" required :options="$levels" :value="$user->level->value" />
                        @if (count($departments) > 1)
                            <x-select name="department_id" label="Department" required :options="$departments" :value="$user->department_id" />
                        @else
                            <input type="hidden" name="department_id" value="{{ array_key_first($departments) ?? $user->department_id }}">
                        @endif
                    </div>
                    <div>
                        <label for="description" class="field-label">Description <span class="font-normal text-muted">(optional)</span></label>
                        <textarea id="description" name="description" rows="3" maxlength="2000" class="field-input field-textarea"
                            @error('description') aria-invalid="true" aria-describedby="description-error" @enderror
                            placeholder="Course code, topics covered, year of the past questions…">{{ old('description') }}</textarea>
                        @error('description')
                            <p id="description-error" class="field-error">{{ $message }}</p>
                        @enderror
                    </div>
                </x-card>

                <x-card class="space-y-4">
                    <h2 class="upload-step"><span>3</span> Cover <span class="font-normal text-muted">(optional)</span></h2>
                    <p class="text-sm text-muted">Skip this and we'll use the first page.</p>
                    <div class="upload-cover">
                        <input id="cover" name="cover" type="file" accept="image/jpeg,image/png,image/webp" class="field-input field-file" data-upload-cover
                            @error('cover') aria-invalid="true" aria-describedby="cover-error" @enderror>
                        @error('cover')
                            <p id="cover-error" class="field-error">{{ $message }}</p>
                        @enderror
                    </div>
                </x-card>

                {{-- Shown by upload.js while it prepares and sends the upload. --}}
                <div class="upload-progress" data-upload-progress hidden>
                    <div class="flex items-center justify-between gap-4 text-sm">
                        <span class="font-medium" data-upload-step role="status" aria-live="polite">Preparing…</span>
                        <span class="text-muted tabular-nums" data-upload-percent></span>
                    </div>
                    <div class="upload-bar" aria-hidden="true"><span data-upload-bar></span></div>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <x-button icon="upload" data-upload-submit>Upload for review</x-button>
                    @if ($remaining !== null)
                        <p class="text-sm text-muted">{{ $remaining }} {{ \Illuminate\Support\Str::plural('upload', $remaining) }} left today</p>
                    @endif
                </div>
            </form>

            <aside class="space-y-4 lg:sticky lg:top-24">
                <x-card class="space-y-3">
                    <div class="flex items-center gap-3">
                        <x-icon-tile name="camera" tone="blue" size="sm" />
                        <h2 class="text-base">Good page photos</h2>
                    </div>
                    <ul class="upload-tips">
                        <li>Lay the page flat in good light, without shadows.</li>
                        <li>Fit the whole page in the frame, held straight.</li>
                        <li>Add the pages in order; you can remove a page before uploading.</li>
                    </ul>
                </x-card>
                <x-card class="space-y-3">
                    <div class="flex items-center gap-3">
                        <x-icon-tile name="scan-text" tone="violet" size="sm" />
                        <h2 class="text-base">Searchable text</h2>
                    </div>
                    <p class="text-sm text-muted">Your phone reads the words on each page before uploading, so classmates can find the book by what's inside it. Nothing extra to do.</p>
                </x-card>
                <x-card class="space-y-3">
                    <div class="flex items-center gap-3">
                        <x-icon-tile name="clock" tone="yellow" size="sm" />
                        <h2 class="text-base">What happens next</h2>
                    </div>
                    <p class="text-sm text-muted">A reviewer checks your upload. Once it's approved, it appears in the library for your level. You can follow it in <a href="{{ route('uploads.index') }}" class="link">your uploads</a>.</p>
                </x-card>
            </aside>
        </div>
    @endif
</x-layouts.app>
