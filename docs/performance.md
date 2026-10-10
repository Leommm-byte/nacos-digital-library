# Performance, offline and accessibility checks

How the app is kept fast, what works offline, and the checks that guard
both. Added in PR 14.

## Performance budget

Checked on every pull request (`.github/workflows/ci.yml`):

| What | Limit | Where |
|---|---|---|
| CSS on every page (gzipped) | 24 KB | `scripts/check-budget.mjs` |
| JavaScript on every page (gzipped) | 24 KB | same |
| Fonts (two variable fonts) | 80 KB | same |
| Reader / assistant / elections scripts | 8 / 6 / 4 KB | same |
| Queries per busy page | at most 30, and the same with 10x the data | `tests/Feature/PerformanceBudgetTest.php` |

PDF.js (about 500 KB) loads only in the reader; Tesseract only when
uploading photos. Raise a limit only on purpose, and say why in the commit.

## Load test

`tests/load/student-day.js` (k6) plays a busy hour against
`LoadTestSeeder` data (3,000 books with page text, 1,500 students on the
nominal roll, an open election):

- up to 150 signed-in students at once, each browsing the home page, the
  library, a search, a book, the reader (a 256 KB range of the PDF), saved
  books, the elections with live results, and the assistant, with a few
  seconds of reading between clicks;
- during the peak, 300 other students vote, three arriving every second.

It fails if more than 1% of requests fail, a check fails, or a request
type's 95th percentile goes over its limit (1.5 s for pages, 2 s for
search, the assistant and voting, 300 ms for live results, 3 s for
logging in, which checks the password with bcrypt, slow on purpose).
Failed requests are listed by kind and status in the run's summary. Afterwards
the workflow checks every ballot was recorded once and the live results
show the same count.

`.github/workflows/load-test.yml` runs it in the app's Docker image
(Apache, PHP 8.3, MySQL 8, database sessions and cache, Apache capped at
20 workers like a shared host). Run it from the Actions tab ("Load test",
"Run workflow"); it also runs when the load test files change. The test
machine is not DreamHost, so read the numbers as "does anything fall over
or grow out of hand", not as exact live timings.

Latest run (October 2026, test machine): 11,212 requests from up to 210
students at once, none failed, every check passed. 95th percentiles: pages
27–52 ms, search 83 ms, the assistant 66 ms, a ballot 59 ms, a PDF page
range 31 ms, live results 1 ms (a static file), logging in 202 ms. All
300 ballots were recorded once and the live results showed 300.

Things the test found and that are fixed: ballots for the same candidates
deadlocked under a rush (candidates are now locked in id order, with
retries), and voters queued behind each other to rewrite the results file
(a ballot now only marks it stale; one writer catches up).

The script keeps each student's cookies between rounds (`noCookiesReset`),
as a browser does, and every page check requires the signed-in page, so a
student signed out by mistake fails the run instead of browsing as a guest.

To run it on your machine against the Docker stack:

```
docker compose exec app php artisan db:seed --class=LoadTestSeeder
k6 run -e BASE=http://localhost:8080 tests/load/student-day.js
```

Never run it against the live site: it creates load on purpose, and
DreamHost's terms don't allow load testing shared servers.

## Offline

- `public/manifest.webmanifest` and `/sw.js` (`resources/sw/sw.js`, served
  by `ServiceWorkerController` with the build's asset lists and a version
  that changes every deploy).
- The worker keeps the app's look (CSS, JS, fonts, icons, about 200 KB)
  and two public pages, `/offline` and `/offline/read`, fetched without
  cookies so they hold nothing personal. Pages always come from the
  network; the offline page appears only when that fails.
- "Keep offline" on a book stores its PDF in the phone's Cache Storage,
  with PDF.js, under the student's opaque owner id. The worker serves the
  PDF's byte ranges, so the normal reading link works offline.
- Kept books are deleted on logout, and when another account signs in on
  the phone.
- Nothing else personal stays usable in the browser's cache: signed-in
  pages are sent with `no-store`, and book files with `no-cache` and an
  ETag, so the browser checks with the server first (an empty 304 when
  unchanged) and a logged-out phone can't open them. Logout doesn't send
  `Clear-Site-Data`: Chrome then walks its whole cache, every site's, and
  logging out took seconds.
- Service workers only run on HTTPS or `localhost`, so "Keep offline" and
  the install card don't appear when a phone opens the dev machine by its
  IP (`http://192.168…`). To try them on a phone, use Chrome's USB port
  forwarding (`chrome://inspect`, forward 8080 to `localhost:8080`) or an
  HTTPS tunnel.

## Accessibility

Every screen in `scripts/screenshots.mjs` is checked with axe-core (WCAG
2.2 AA and best practices) in both themes on phone and desktop. Serious or
critical problems fail the run; all findings are listed in
`accessibility.md` on the `screenshots/<branch>` branch.
