{{--
    The reader for books kept offline. The service worker shows it when a
    kept book's reading page can't load; resources/js/offline.js fills in
    the book from what was saved on the phone, and the PDF comes from the
    phone too. The same for everyone: nothing personal in the page itself.
--}}
<x-layouts.reader title="Reading offline">
    <div class="reader" data-offline-reader
        data-assets="{{ asset('build/pdfjs') }}/">

        <header class="reader-bar">
            <a href="{{ url('/offline') }}" class="btn btn-ghost btn-icon" aria-label="Back to books on this phone">
                <x-icon name="arrow-left" />
            </a>
            <div class="min-w-0 flex-1">
                <h1 class="reader-title" data-offline-title>Reading offline</h1>
                <p class="reader-subtitle" data-offline-author></p>
            </div>
            <x-badge variant="neutral" class="hidden sm:inline-flex"><x-icon name="wifi-off" /> Offline</x-badge>
            <x-theme-toggle />
        </header>

        @include('library.partials.reader-body', ['fileUrl' => true, 'startPage' => 1, 'backUrl' => url('/offline')])
    </div>
</x-layouts.reader>
