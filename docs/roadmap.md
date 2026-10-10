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
| Nominal roll | Kept class by class (programme and level). Governors fill a template (class at the top, then matric number, name, email); the admin uploads each class's list as Excel or CSV, replacing that class only. Classes can also be added to (a file of extra students, or one student at a time), and single students removed. The roll is locked while voting is open in an election limited to it. Accounts are created from the roll with random first passwords on printable slips. |
| Ballot secrecy | Who voted (`election_voters`) and what was voted (`election_votes`, random UUIDv4 ids, no timestamps) are stored separately. One ballot per election covering every position; skipping a position is allowed. |
| Design | Keep the original green/yellow identity, improved: design tokens, dark mode, self-hosted subset fonts and icons, cheap GPU-friendly animations that respect reduced motion. |
| Matric numbers | `F/ND/24/1234567`: programme letter, ND/HND (HD), two-digit entry year, exactly 7 digits. A year that hasn't started, or one more than 10 years ago, is refused. The older `ND/2019/CS/1234` format is no longer accepted. |
| Display name | Optional at signup and in the profile (30 characters, letters). Greetings use it; official places (roll, slips, audit log, watermark, admin pages) keep the full name. |
| Courses (arms) | HND computing is split into arms read from the matric number's 4th digit of 7: `…/321`**`1`**`…` Software and Web Development (SWD), `…/321`**`2`**`…` Networking and Cloud Computing (NCC). ND has no arms yet. Built so arms can be added later (AI and cybersecurity are rumoured) and ND could get them too. Each arm is its own class with its own nominal roll and governor. |
| Programme lengths | Full-time ND and HND: 2 years. Part-time ND and HND: 3 years. CODFEL: ND only, 2 years (could change). |
| Graduates | Keep read-only library access; can't vote or upload. |
| Governors | Each is tied to exactly one class (level, programme and arm). |
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
- [x] **12. Admin panel.** Users (roles, suspension, class, two-step reset, new password slip), accounts created from the nominal roll with random first passwords on printable slips, books, reports, audit log viewer, settings in the database. The nominal roll moves to one list per class, uploaded as Excel or CSV. Decided with the owner: CODFEL matric numbers start with D/; results update after every ballot.
- [x] **13. Assistant.** A floating chat on signed-in pages (and a full page at /assistant that works without JavaScript). A free keyword helper always answers: books by title, author, topic or level, the student's uploads and reviewer notes, saved books, reading, notifications, activity, open elections and how-tos. With an Anthropic API key, Claude answers instead, with read-only tools limited to the library and the student's own data, and helps with study (explaining, summarising and quizzing from library books, without doing graded work), up to a daily limit per student set in admin settings; the helper takes over when the limit is reached or the AI fails. Decided with the owner: free by default, AI when a key is set; both app help and study help.
- [x] **14. Launch polish.** Installable app (manifest, icons, install card) and offline support: an offline page, and books kept offline on request and read from the phone (removed on logout). Share tags. Accessibility checked with axe-core on every screen. Performance budget in CI (asset sizes, queries per page that don't grow with data) and a k6 load test of a busy hour and an election rush (`docs/performance.md`). Decided with the owner: offline reading of chosen books, not just an offline page.
- [x] **14.5. Quality of life.** From the owner's testing: candidate photos on the ballot and the results; a "Books read" page behind the home page's tile; a neater audit log (a page of 25 grouped by day, count and page links at the top) with CSV export of the filtered log.
- [x] **14.6 A. Sign-up and roll fixes.** Optional display name; matric numbers with exactly 7 digits and a current entry year (one rule for signup, the roll and candidates); paging through the nominal roll stays on the list.
- [x] **14.6 B. HND arms (SWD/NCC).** The arm (course) is part of a class, read from the matric number's 4th digit of 7 (3211… SWD, 3212… NCC) and never chosen; one roll per arm (17 classes, by programme length); the template has a Course line; elections can be limited to arms; arms shown on the profile, home page and admin pages. Classes and programme lengths live in `config/classes.php`, so a new HND arm (AI, cybersecurity) or a split ND is a config change.
- [x] **14.6 C. Timetables.** Weekly class timetables: each class sees its own week (today's lectures marked now/next, the next lecture, a colour per course, Saturday only when used); its governor keeps it, admins keep any class's; lectures added one at a time or a whole week uploaded from the Excel template (days carried down, "8-10am" time cells, Excel times). An exam timetable kept by admins and seen by everyone: papers grouped by day, students' own papers first (by level, programme and course), past papers hidden; added one at a time or uploaded (day-first dates, Excel dates, "HND1 SWD" in a For column). Today's lectures and the next exam on the home page; Timetable replaces Saved in the main navigation (Saved moves to the account menu). Rows are archived, not deleted, so PR D can start a new session.
- [ ] **14.6 D. New session.** An admin starts the new session: everyone moves up a level by programme length, final years graduate, timetables are archived and rolls start fresh; previewed first and undoable.
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
