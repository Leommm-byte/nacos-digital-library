{{--
    "Install the NACOS app" pop-up (resources/js/install.js): shown once on
    a phone, from the second visit, when the app isn't installed. On
    Android the button opens the browser's install prompt; on iPhone and
    iPad the steps are shown. "Not now" hides it for 30 days; the smaller
    card (partials/install-banner) stays as the reminder.
--}}
<dialog class="install-dialog" data-install-dialog tabindex="-1" aria-labelledby="install-dialog-title" aria-describedby="install-dialog-text"
    @unless (app()->isProduction()) data-install-preview @endunless>
    <div class="install-dialog-head">
        <img src="{{ asset('icons/icon-192.png') }}" alt="" width="56" height="56" class="install-dialog-icon">
        <div class="min-w-0 flex-1">
            <h2 id="install-dialog-title" class="text-lg">Install the NACOS app</h2>
            <p id="install-dialog-text" class="mt-1 text-sm text-muted">Open it from your home screen like any app: quicker to reach, full screen, and the books you keep offline work without data.</p>
        </div>
        <button type="button" class="install-dialog-close" data-install-dialog-dismiss aria-label="Close"><x-icon name="x" /></button>
    </div>

    <ol class="install-steps" data-install-dialog-ios hidden>
        <li><x-icon name="share" class="install-step-icon" /> <span>Tap the <strong>Share</strong> button in Safari.</span></li>
        <li><x-icon name="square-plus" class="install-step-icon" /> <span>Scroll down and tap <strong>Add to Home Screen</strong>.</span></li>
        <li><x-icon name="circle-check" class="install-step-icon" /> <span>Tap <strong>Add</strong>. The app is now on your home screen.</span></li>
    </ol>

    <div class="install-dialog-actions">
        <x-button type="button" variant="secondary" data-install-dialog-dismiss>Not now</x-button>
        <x-button type="button" icon="download" data-install-dialog-install hidden>Install app</x-button>
        <x-button type="button" data-install-dialog-done hidden>Got it</x-button>
    </div>
</dialog>
