# Legacy feature checklist

Everything the original app (`legacy/`) does, so the rebuild can be checked
for parity before go-live. Built from a full read of every legacy file.

How to use it:

- **Keep**: behaviour the rebuild must have (improved where noted). Tick it
  in the PR that delivers it.
- **Fix**: legacy bugs and security holes. Tick once the rebuild is shown
  (usually by a test) not to have them.
- **Drop**: deliberately not carried over, with the reason.

Questions for the owner are collected at the end.

## Authentication and accounts (PR 3)

Keep:

- [x] Log in with matric number and password; matric trimmed and uppercased.
- [x] Signed-in users visiting login or signup are sent home.
- [x] Same error for an unknown matric number and a wrong password, with no timing difference.
- [x] Failed logins are limited, without the legacy lockout (see Fix).
- [x] On success: session regenerated, last login time and IP recorded.
- [x] Audit log of logins, failed logins (with matric), throttling, logouts and signups.
- [x] Signup: full name (3+ characters), matric number, department, level (ND1 to HND3), programme (Full-time, Part-time, CODFEL), password and confirmation.
- [x] Matric formats: `F|P|C/ND|HND|HD/YY/digits` (year 19 onwards) and the older `ND|HND/YYYY/DEPT/digits`.
- [x] Password rule: 8+ characters with upper and lower case, a number and a symbol (plus a known-breach check in production).
- [x] Duplicate matric numbers are rejected with a clear message.
- [x] Password show/hide button on login, signup and password forms.
- [x] Live password-rule checklist on signup (replaces the legacy strength bar, whose rules differed from the server's).
- [x] Logout.
- [x] Forced password change after logging in with a temporary password; every other page redirects there until it's done.
- [x] Roles in order (student < course rep < governor < admin) with "at least this role" checks.
- [x] CSRF protection on every form.
- [x] Session cookie HttpOnly, SameSite=Lax, Secure in production; HTTPS enforced in production.
- [x] MFA step after the password when MFA is on (PR 4).

Fix:

- [x] Suspended users could still log in; suspension is now enforced at login and ends live sessions.
- [x] The 5-failures, 6-hour account lockout let anyone lock any student out; limits are now per matric+IP, plus a loose per-IP cap.
- [x] Signup accepted any department, level or programme; all are validated now.
- [x] Signup showed raw database errors.
- [x] Logout was a plain GET link with no CSRF check; it is a POST now.
- [x] New accounts had to log in again after signup; they are now signed in.
- [x] Password message said "8-12 characters" but had no maximum.
- [x] Schema was changed by `ALTER TABLE` during logins; migrations only now.

Drop:

- [ ] One account per device fingerprint (*question 1*). Not carried over yet.
- [x] Dead client-side validation code in `auth.js`.

## Password reset and MFA (PR 4)

Keep:

- [x] Forgot password by matric number, generic response either way.
- [x] Reset link valid for 1 hour, single use.
- [x] TOTP MFA (6 digits, 30 seconds, ±1 step tolerance) with a QR code and manual secret, plus an "open in authenticator app" button for setting up on the same phone.
- [x] Enabling MFA requires a valid code; 8 single-use recovery codes.
- [x] MFA login accepts a TOTP code or a recovery code; "start again" goes back to login.

Fix:

- [x] The reset link was printed on the page ("Dev Link"), so anyone could reset any account; links are now emailed to a verified address only, with course rep/admin codes as a fallback.
- [x] No rate limit on forgot password or MFA verification.
- [x] A reset didn't end other sessions or clear the temporary-password flag.
- [x] The MFA secret was saved before it was confirmed; it now stays in the session until a code from the app works.
- [x] Backup codes were stored in plain text and shown on every visit; they are now hashed and shown once.
- [x] Disabling MFA needed no code or password; it now needs a current code or a recovery code.
- [x] The QR code came from the deprecated third-party Google Charts API; it is now drawn in the browser by a self-hosted library.
- [x] A TOTP code could be used more than once within its 30 seconds; each is now single use.

New in the rebuild:

- [x] Email collected (optionally) at signup and in Account settings, with verification; signed-in users without a verified email see a reminder.
- [x] One-time reset codes (30 minutes, single use, hashed): admins for anyone, course reps for students in their own department and level.

## Profile and settings (PR 5)

Keep:

- [x] Profile shows name, matric, programme, department, level, role and previous login (time and IP, to spot sign-ins that weren't you).
- [x] Edit full name, department, level and programme; matric number is read-only.
- [x] Live preview card while editing.
- [x] Account settings: MFA (legacy "Account Settings" was only the MFA page), plus changing your own password.

Fix:

- [x] After saving, the header and session showed stale department and level (details are now read from the database on every request).

New in the rebuild:

- [x] Changing your password needs the current one and logs out every other device.
- [x] Course reps and above can't move themselves to another class (department or level), since their reset-code powers depend on it; an admin does that.

## Home, dashboard and announcements (PR 10)

Keep:

- [x] Greeting by Lagos time of day with the first name; role, department and level badges (PR 6.5).
- [ ] Academic profile summary.
- [ ] Stats: books read, bookmarks, uploads.
- [ ] Continue reading: the 2 most recent books with progress bars.
- [ ] Recommended books: same department and level first, then same level.
- [ ] Recent activity (the user's own last 5 actions, in plain words).
- [ ] Up to 3 live announcements, newest first, with an empty state.
- [ ] Header: logo, search shortcut to the library, account menu (Profile, Saved, Elections, Library, Settings, Admin panel for admins, Logout).

Fix:

- [ ] About 14 sequential queries and `ORDER BY RAND()`; stats are cached now.
- [ ] Recent activity printed raw audit action names without escaping.
- [ ] Announcements expired at midnight at the start of their end date.
- [ ] Governors had no link to the approval queue.

Drop:

- [ ] Computed but never shown: trending books, pending/approved/notification counts.

## Library, search and bookmarks (PR 6)

Keep:

- [x] Catalog of approved books, newest first (also "most read" and A–Z).
- [x] Search by title or author (and description), filter by level (and department when more than one is open).
- [x] Cards: cover (generated placeholder when there's none), title, author, level, bookmark toggle; "No books found" state. "Read" is on the book's page (reader in PR 7).
- [ ] Shortcut to upload from the library (appears automatically with PR 8).
- [x] Bookmark toggle without a page reload, with a confirmation message.
- [x] Saved books page, newest first, with remove.

Fix:

- [x] No paging; unindexable `LIKE '%…%'` search. Now 24 per page with MySQL full-text search ranked by relevance.
- [x] Bookmark toggle had no CSRF check and allowed unapproved books.
- [x] Saved books listed unapproved books, and its count didn't update after removing one.
- [x] Covers were linked straight from the protected uploads folder; they are now served through a permission-checked controller, cached by the browser and lazy-loaded.
- [x] Department hard-coded to Computer Science in queries; the catalog now shows every open department.

New in the rebuild:

- [x] Book detail page with description, pages, reads, who shared it and "more for your level".
- [x] Unapproved books return "not found" (not "forbidden") to everyone except the uploader and reviewers.
- [x] Search shortcut in the header.

## Reader (PR 7)

Keep:

- [x] Open a book by its public id. Books not yet approved stay hidden (404) from everyone except the uploader and governors/admins, who see the status in the reader bar (same rule as the book page, PR 6).
- [x] PDF only reachable through an authenticated, permission-checked route.
- [x] Page fitted to the screen (width on phones, whole page on larger screens); previous/next, page X of Y with page jump, zoom (60% to 300%), arrow keys, swipe.
- [x] "Unable to load this book" error state, with retry; a "not ready yet" state for books without a file.
- [x] Reading progress saved shortly after each page turn and when leaving (sendBeacon); "completed" recorded once at 95%.
- [x] Right-click and print/save shortcuts discouraged on the reader, printing blanked (deterrent only), plus the new matric-number watermark drawn on every page.

Fix:

- [x] The whole PDF downloaded before page 1 showed; HTTP Range streaming now, with auto-fetch off, so only the pages read are downloaded.
- [x] Views and history were recorded before the approval check. Now only after it, only for approved books, and one view per book per session.
- [x] Always opened at page 1; it now resumes at the saved position (and the book page says "Continue reading · page N").
- [x] Progress could go backwards or above 100%, with no ownership check. The percentage only rises, is clamped to the file's page count, and is tied to the signed-in user.
- [x] A database token row was created on every page load and never used up. No tokens: the session and the book policy guard the file.
- [x] PDF.js came from a CDN; it is self-hosted now (6.4, legacy build for older phones), with its fonts, character maps and decoders.
- [x] PDF URL with its token logged to the console; book details written to the error log on every load. Nothing is logged.

## Uploads and OCR (PR 8)

Keep:

- [x] Any signed-in user can upload (all roles).
- [x] Title, author, level (and department when more than one is active), optional description and cover image (JPG, PNG, WebP, up to 2 MB).
- [x] PDF upload, checked by content (`%PDF-` and its detected type), up to 10 MB.
- [x] Scanned upload: up to 60 photos of pages (5 MB each after shrinking), turned upright and shrunk on the phone, re-encoded on the server, and compiled into one PDF (pure PHP, JPEGs embedded as-is).
- [x] OCR of scanned pages: on the phone with self-hosted Tesseract (free, no key). Optional AI pass on the server when `ANTHROPIC_API_KEY` is set (queued, one page per job).
- [x] Cover generated from page 1 when none is given (photos: first page; PDFs: drawn by PDF.js on the phone).
- [x] Uploads start as pending review.
- [x] Success page with the title, "waiting for review" and links to upload another or go home; plus a "Your uploads" list with each upload's status.
- [x] PDF/Photos choice, drag and drop, file name and size shown, page thumbnails with remove, size errors before uploading, upload progress bar.

Fix:

- [x] Cover images had no size limit (2 MB now, re-encoded).
- [x] Any unknown upload type was treated as a scan. The type is explicit and validated.
- [x] Scanned PNG pages produced broken PDFs in the fallback compiler. Every page is re-encoded as JPEG first (transparency becomes white).
- [x] Covers were never generated for scanned uploads.
- [x] Orphaned files left behind on failure. Everything is written in one transaction and written files are deleted if it fails.
- [x] The OCR endpoint could be called directly by any signed-in user. There is no OCR endpoint: OCR runs on the phone, and the AI pass only from the queue.
- [x] OCR text was stored but never shown or searched; it is now searchable (its own full-text index; title matches rank higher).
- [x] The page said "approved automatically" while uploads were pending.
- [x] The OCR preview on the success page never appeared. The status page shows how much of the text is ready.
- [x] OCR ran during the upload request; it is now on the phone, with the optional AI pass queued and shown with progress.

New:

- [x] Per-user caps: 10 uploads per 24 hours and 20 waiting for review (governors and admins exempt).
- [x] Optional ClamAV scan (`UPLOADS_CLAMAV`); without it, files are still checked by content, images re-encoded and everything served behind login with `nosniff`.
- [x] Photos lose their metadata (such as GPS location) when re-encoded.

## Moderation and approvals (PR 9)

Keep:

- [ ] Governors and admins review pending uploads (not their own).
- [ ] Review page with details and an inline preview.
- [ ] Approve, reject or request changes, each with a comment.
- [ ] Admin list filtered by department, level and status, with preview, approve, reject and delete.
- [ ] Pending count badge in the admin sidebar.

Fix:

- [ ] Review comments only went to the audit log; uploaders were never told. Reasons now reach the uploader by notification.
- [ ] "Returned for edit" had no way to edit and resubmit, and admin lists showed it as "Rejected".
- [ ] No check that a book was still pending when reviewed.
- [ ] Deleting a book left covers, thumbnails, bookmarks and history behind.
- [ ] No link anywhere to the approval queue.

## Elections (PR 11)

Keep:

- [ ] Students see the open election or "No election active".
- [ ] One choice per position, all positions on one ballot; skipping a position is allowed.
- [ ] Clear confirmation after voting, and a "you have voted" state.
- [ ] Live standings and a countdown to the closing time.
- [ ] Elections close automatically at their end time.
- [ ] Admin: create the election, add positions and candidates (name, matric, manifesto), launch for a set duration (1, 2, 6, 12 or 24 hours), stop early.
- [ ] Admin live standings with vote counts and percentages.

Fix:

- [ ] Votes were stored with the voter's id, so ballots weren't secret; voters and votes are now stored separately.
- [ ] No check that a candidate belongs to the position, or the position to the open election.
- [ ] Anyone could fetch full tallies of any election, including drafts.
- [ ] Results polled every 3 s per student with several queries and an `UPDATE`; now a static snapshot.
- [ ] Candidate names inserted into the page unescaped (XSS).
- [ ] Countdown dropped days and used the browser's time zone.
- [ ] Closing depended on someone loading a page after the end time; the scheduler does it now.
- [ ] Candidates could be changed while voting was open; a closed election could be reopened.
- [ ] Only one election at a time, and "Reset engine" wiping everything was the only way to start another.
- [ ] Eligibility rules (*question 3*).

## Admin panel (PR 12)

Keep:

- [ ] Admin-only area with sidebar navigation (Dashboard, Moderation, Books, Users, Bulk register, Announcements, Elections, Settings, Reports) that works on phones.
- [ ] Dashboard: pending, approved, users and uploads-today counts; recent actions; recent uploads.
- [ ] Books list with status and permanent delete (with confirmation).
- [ ] Users list (name, matric, level, department, role, status).
- [ ] Suspend and reactivate users; promote to course rep and demote.
- [ ] Opening or closing a department clears the catalog's cached department list (`catalog:active-departments`).
- [ ] Turn off a user's two-step verification when they lose their phone and recovery codes (needed since PR 4).
- [ ] Bulk student import from CSV (comma or semicolon) or XLSX, up to 5 MB.
- [ ] Bulk import columns: `matric_number` (or `matric`/`username`) and `surname` (or `last_name`) required; full name, department, level and programme optional with defaults.
- [ ] Per-row import results ("Imported N, skipped M"); duplicates skipped.
- [ ] Imported accounts must change their password on first login.
- [ ] Announcements: create, edit and delete with title, message, start/end dates and active/draft.
- [ ] Settings: site title, max upload size, allowed file types, academic session.
- [ ] Reports: books per department and per month.

Fix:

- [ ] Temporary password was the student's surname. Now random passwords with printable slips.
- [ ] No way to make someone a governor or admin; an admin could suspend themselves.
- [ ] Settings were saved to a file and never read; they are stored in the database and applied now.
- [ ] Reports merged months across years and hard-coded department names.
- [ ] "Recent logins" computed but never shown (audit log viewer replaces it).

## Assistant (PR 13)

Keep:

- [ ] Floating assistant button and chat panel on home.
- [ ] Find books by title, check my upload statuses, list my bookmarks, latest notifications, my recent activity, otherwise a help menu (legacy was keyword matching, not AI).

Fix:

- [ ] No CSRF check on messages; answers inserted as raw HTML.

## Offline, install and general UX (PR 2 and PR 14)

Keep:

- [x] Self-hosted fonts and icons, no third-party CDNs (PR 2).
- [x] Dark mode (legacy had the code but no button) (PR 2).
- [x] Shared header, footer and account menu that closes on Esc and outside click (PR 2).
- [x] Small, compressed logo and favicon (PR 2).
- [x] Page titles and descriptions; `noindex` on error pages (PR 2).
- [x] Brand presence: green brand panel on auth pages, green hero on the home pages (PR 6.5).
- [x] Coloured icon tiles, softer surfaces and section headers with "See all" links (PR 6.5).
- [ ] Web app manifest and service worker with an offline fallback page (PR 14).
- [ ] Install prompt: Android/desktop install button; iOS "Share, Add to Home Screen" steps; hidden when installed; dismiss for 24 h (PR 14).
- [ ] Confirmation dialogs (legacy AppModal) for destructive actions (as features need them).
- [ ] Open Graph/Twitter share tags (PR 14).
- [x] Audit log of security-relevant actions (PR 1).

Fix:

- [ ] The service worker cached signed-in and admin pages, and could cache PDFs (PR 14).
- [x] `.htaccess` didn't block `includes/`, protected uploads or the config file. Now only `public/` is web-served (PR 1).
- [x] Inline styles and handlers everywhere; the CSP forbids them now (PR 2).
- [x] Heavy `backdrop-filter` blur and large shadows (PR 2).
- [x] Error details and database errors shown to users (PR 1).

## Drop (not carried over)

- [x] `modal_test.php`, an unauthenticated demo page.
- [x] Empty stub files (`approval/approve.php`, several empty JS files).
- [x] Runtime `ALTER TABLE`/`CREATE TABLE`; migrations only.
- [x] Leftover file-based election data and the 22 orphaned scan images (see roadmap open items).
- [x] Unused columns and tables (`visibility`, `upload_sessions`).

## Questions for the owner

1. **One account per device.** Legacy signup refused a second account from
   the same browser fingerprint. It is easy to bypass and blocks students
   who share a phone or use a cyber café. Bring it back, or rely on unique
   matric numbers (plus admin review)?
2. **Departments.** Legacy profile and reports mentioned Mass Communication
   and Accountancy, but signup and the library were Computer Science only.
   The rebuild seeds all three with only Computer Science active. Is that
   right?
3. **Election eligibility.** Legacy let every account vote, including
   admins. Should voting be limited (for example to students, by level, or
   to dues-paying members)?
4. **Matric numbers that don't exist.** Signup checks the *format*
   (legacy rule: entry year 19 or later, so `F/HD/18/…` is refused), but a
   format check can't tell a real number from an invented one. Options to
   decide later: keep open signup; only allow matric numbers on an
   official list the admin imports (bulk import, PR 12) and treat that as
   an allow-list; or let anyone sign up but hold new accounts until a
   course rep confirms them. The entry-year rule itself (19+, any year, or
   a rolling window) can be settled at the same time.
