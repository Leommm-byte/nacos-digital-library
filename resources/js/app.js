import { initTheme } from './theme';
import { initMenus } from './menus';
import { initReveal } from './motion';
import { initForms } from './forms';
import { initQr } from './qr';
import { initPreview } from './preview';
import { initBookmarks } from './bookmarks';
import { initAutosubmit } from './autosubmit';
import { initToast } from './toast';
import { initOffline } from './offline';
import { initInstall } from './install';

initTheme();
initMenus();
initReveal();
initForms();
initQr();
initPreview();
initBookmarks();
initAutosubmit();
initToast();
initOffline();
initInstall();

// The reader (and PDF.js with it) only loads on the reading page.
if (document.querySelector('[data-reader]')) {
    import('./reader').then(({ initReader }) => initReader());
}

// The upload form and upload status pages.
if (document.querySelector('[data-upload], [data-upload-status]')) {
    import('./upload').then(({ initUpload, initUploadStatus }) => {
        initUpload();
        initUploadStatus();
    });
}

// The page previews on the review page.
if (document.querySelector('[data-pdf-preview]')) {
    import('./review').then(({ initReviewPreview }) => initReviewPreview());
}

// Election countdowns, the ballot, live results and candidate photos.
if (document.querySelector('[data-countdown], [data-ballot], [data-live-results], [data-photo-picker]')) {
    import('./elections').then(({ initElections }) => initElections());
}

// The assistant chat (floating panel on signed-in pages, and /assistant).
if (document.querySelector('[data-assistant]')) {
    import('./assistant').then(({ initAssistant }) => initAssistant());
}
