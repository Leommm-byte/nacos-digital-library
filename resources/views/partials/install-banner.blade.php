{{--
    "Install the app" card (resources/js/install.js). Hidden unless the
    browser can install the app (Android, desktop Chrome and Edge) or it's
    an iPhone or iPad, where the steps are shown instead. Never shown once
    installed, and "Not now" hides it for a day.
--}}
<section class="install-banner" data-install hidden aria-labelledby="install-title">
    <x-icon-tile name="smartphone" tone="green" />
    <div class="min-w-0 flex-1">
        <h2 id="install-title" class="font-display font-bold">Install the NACOS app</h2>
        <p class="text-sm text-muted" data-install-text>Open it from your home screen like any app, and read the books you keep offline without data.</p>
        <p class="text-sm text-muted" data-install-ios hidden>
            Tap <x-icon name="share" class="inline size-4 align-text-bottom" /><span class="sr-only">the Share button</span> in Safari, then
            <strong>Add to Home Screen</strong> <x-icon name="square-plus" class="inline size-4 align-text-bottom" />.
        </p>
    </div>
    <div class="install-actions">
        <x-button type="button" size="sm" icon="download" data-install-button hidden>Install</x-button>
        <x-button type="button" size="sm" variant="ghost" data-install-dismiss>Not now</x-button>
    </div>
</section>
