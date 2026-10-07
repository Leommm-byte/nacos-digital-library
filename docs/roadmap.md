# Rebuild roadmap

The original app (now in `legacy/`) was rebuilt from scratch on Laravel.
Work ships one PR at a time: open the PR, the owner tests it locally, merges,
and the item is ticked off here.

## Decisions so far

| Topic | Decision |
|---|---|
| Stack | Laravel 12, PHP 8.3, MySQL 8. No framework-free rewrite. |
| Hosting | DreamHost shared ("Web Hosting Growth"), user `new_nacos_admin`, PHP 8.3, SSH enabled. No Redis or long-running processes; database-backed session, cache and queue. |
| Cron | Confirmed: DreamHost's custom cron schedule allows every minute. The cron runs `schedule:run` every minute as `new_nacos_admin`, which also drains the job queue. |
| Launch state | Not launched yet. No live data to migrate; the legacy app does not need patching. |
| Password reset | Both: email reset links (collect email at signup or next login) and one-time codes issued by an admin or course rep as a fallback. |
| Election results | Public and live during voting, for transparency. Serve a small static `live.json` snapshot rebuilt at most every few seconds; clients poll it with conditional requests (ETag/304), pause when the tab is hidden, and add jitter. No WebSockets/SSE on shared hosting. Leave a hook to switch to Pusher/Ably later. Show totals and turnout only. The snapshot is rewritten after every ballot so voters see their vote counted (the owner chose this over releasing totals in batches). |
| Election eligibility | Per election: limited by default to students on the nominal roll (the official list of current students an admin imports), and optionally to some levels and a range of matric entry years. The roll's level beats the one chosen at signup. Only admins create and run elections (governors are often candidates). |
| Ballot secrecy | Who voted (`election_voters`) and what was voted (`election_votes`, random UUIDv4 ids, no timestamps) are stored separately. One ballot per election covering every position; skipping a position is allowed. |
| Design | Keep the original green/yellow identity, improved: design tokens, dark mode, self-hosted subset fonts and icons, cheap GPU-friendly animations that respect reduced motion. |
| Repository | Work happens in the fork `Leommm-byte/nacos-digital-library`. When everything is done, one PR goes from the fork back to `Tech-Reni/nacos-digital-library`. |

## Open items for the owner

- Answer the questions at the end of [`legacy-checklist.md`](legacy-checklist.md)
  (one account per device, departments, invented matric numbers).
- Rotate the legacy database password (`nacos_db`). It was committed to git.
- Decide whether to delete the 22 scan images in `legacy/uploads/temp_scans/`
  (possibly real student documents) and whether to scrub them from history.

## PRs

Every legacy behaviour, bug to avoid and open question is tracked in
[`legacy-checklist.md`](legacy-checklist.md); each PR ticks off its part.

- [x] **1. Laravel foundation.** Schema, models, Docker stack, CI.
- [x] **2. Design system and layout.** Tokens, dark mode, self-hosted assets, animation system, shared layout and navigation, Content-Security-Policy, compressed logo and optimised images.
- [x] **3. Authentication.** Signup, login, logout; rate limiting that cannot lock other people out; enforced suspension; roles and policies.
- [x] **4. Password reset and MFA.** Email links plus admin/rep codes; email collection; TOTP with a locally generated QR code and hashed recovery codes; a code required to disable it.
- [x] **5. Profile and account settings.**
- [x] **6. Catalog.** Paging, full-text search and filters, book detail page, bookmarks, covers served through controllers, lazy-loaded covers.
- [x] **6.5. UI and UX overhaul.** Audit against the legacy UI and a design standard for every later PR (`docs/design-overhaul.md`); real home pages for guests and students; brand auth layout; shared page header, section, icon tile and empty state components; quieter placeholder covers; settings layout; toasts and busy buttons; CI screenshots of every screen (`scripts/screenshots.mjs`).
- [x] **7. Reader.** HTTP Range streaming, latest PDF.js, HiDPI rendering, prefetch, page jump, saved position, matric-number watermark.
- [x] **8. Uploads.** PDF or photos of pages; on-device OCR (Tesseract) with an optional queued AI pass; per-user limits; optional virus scanning. Decided with the owner: OCR runs on the phone (free), AI only if a key is set; legacy size limits. Uploads are single requests with progress and clear retry messages rather than resumable: with a 10 MB cap and photos shrunk on the phone, chunking added complexity without benefit.
- [x] **9. Moderation.** Review queue and page with preview; approve, request changes or reject with a note; uploaders notified (in the app, and by email if verified) and able to edit and resubmit; approvals history; full clean-up on delete.
- [x] **10. Dashboard and announcements.** Stats, continue reading, recommendations, announcements (posted by governors and admins), profile summary and recent activity; shared parts cached.
- [x] **11. Elections.** Eligibility set per election (nominal roll, levels and matric entry years), single ballot enforced by the database, live public results as described above (static snapshot rewritten after every ballot), automatic closing by the scheduler, admin setup and monitoring, audit trail. Decided with the owner: only admins run elections.
- [ ] **12. Admin panel.** Users, bulk import (from the nominal roll) with random temporary passwords and printable slips, books, reports, audit log viewer, settings in the database.
- [ ] **13. Assistant.** Keyword helper or real AI (to decide).
- [ ] **14. Launch polish.** Offline support, accessibility, performance budget, load testing.
- [ ] **15. Go live.** Deployment to DreamHost, backups, monitoring, launch checklist.

## Why the legacy app was slow (avoid repeating these)

- The reader downloaded whole PDFs before page 1 (no HTTP Range support).
- `ALTER TABLE` / `CREATE TABLE` ran on ordinary page requests.
- Around 14 sequential queries on the dashboard, `ORDER BY RAND()`, no paging,
  unindexable `LIKE '%…%'` search.
- Render-blocking Google Fonts and a CDN icon font on every page; hundreds of
  lines of inline, uncacheable CSS per page.
- A 416 KB PNG logo used as favicon and cover placeholder; no lazy loading.
- Election results polled every 3 s per student, each poll running several
  queries plus an `UPDATE`.
- PHP session locking serialised concurrent AJAX requests.
- Heavy `backdrop-filter` blur and shadows on mobile.
