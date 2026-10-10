{{-- Shown by the service worker when a page can't load without a connection. --}}
<x-layouts.offline title="You're offline">
    <div class="offline" data-offline-page>
        <div class="offline-hero">
            <x-icon-tile name="wifi-off" tone="yellow" size="lg" />
            <h1 class="mt-4 font-display text-2xl font-extrabold tracking-tight md:text-3xl">You're offline</h1>
            <p class="mt-2 max-w-md text-muted">This page needs a connection. Check your data or Wi-Fi; it will open again once you're back online.</p>
            <div class="mt-5 flex flex-wrap justify-center gap-2">
                <x-button type="button" icon="rotate-ccw" data-offline-retry>Try again</x-button>
            </div>
        </div>

        <x-section title="Books on this phone" description="Books you kept offline open without data." class="mt-10">
            <ul class="offline-books" data-offline-books hidden></ul>
            <div data-offline-empty>
                <x-empty-state icon="hard-drive-download" title="No books kept offline yet"
                    text="When you're online, open a book and tap “Keep offline” to read it here without data." />
            </div>
        </x-section>
    </div>
</x-layouts.offline>
