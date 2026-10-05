# NACOS YabaTech Digital Library

Student portal for NACOS YabaTech: digital library and reader, uploads with
OCR, moderation, announcements and executive elections.

Built with **Laravel 12 / PHP 8.3 / MySQL 8**, designed to run on shared
hosting (DreamHost) with no Redis or long-running workers.

> The original app is kept in [`legacy/`](legacy) as a reference while it is
> rebuilt feature by feature. It is not served and will be deleted once the
> rebuild is complete.

## Run it locally

### Option A: Docker (recommended)

Needs only [Docker Desktop](https://www.docker.com/products/docker-desktop/).

```bash
git clone https://github.com/Tech-Reni/nacos-digital-library.git
cd nacos-digital-library
docker compose up -d --build
```

The first start takes a few minutes (it builds the image and installs
dependencies). After that, open:

| What | Where |
|---|---|
| App | http://localhost:8080 |
| Mailpit (every email the app sends) | http://localhost:8025 |
| MySQL | `localhost:33060`, user `nacos`, password `secret` |

The container creates `.env`, generates the app key, runs migrations and
seeds demo data automatically. The `scheduler` container waits until that
setup is finished, and the `assets` container rebuilds CSS and JavaScript
whenever you save a file (refresh the browser to see it). Useful commands:

```bash
docker compose logs -f app                          # follow logs
docker compose exec app php artisan test            # run the test suite
docker compose exec app php artisan migrate:fresh --seed   # reset the database
docker compose down                                 # stop (data is kept)
docker compose down -v                              # stop and wipe the database
```

### Option B: Without Docker

Needs PHP 8.3 (with `pdo_mysql`, `intl`, `gd`, `zip`), Composer, Node 22 and
MySQL 8.

```bash
composer install
npm ci && npm run build       # or `npm run dev` while editing CSS/JS
cp .env.example .env          # then set DB_* to your local MySQL
php artisan key:generate
php artisan migrate --seed
php artisan serve             # http://localhost:8000
```

### Demo accounts

Seeded in local/testing environments only, never in production. All use the
password **`Password1!`**.

| Role | Matric number |
|---|---|
| Admin | `F/HD/21/0000001` |
| Governor | `F/HD/22/0000002` |
| Course Rep | `F/ND/23/0000003` |
| Student | `F/ND/24/0000004` |

## Checks

Every pull request runs these in CI. Run them locally before pushing:

```bash
vendor/bin/pint            # format code (use --test to only check)
vendor/bin/phpstan analyse # static analysis
php artisan test           # test suite
```

## Accounts and roles

- Students sign up at `/signup` with their matric number and log in at
  `/login`. Matric formats: `F/ND/24/1234567` (F, P or C; ND, HND or HD) and
  the older `ND/2019/CS/1234`.
- Roles, from least to most privileged: student, course rep, governor,
  admin. Protect routes with `->middleware('role:governor')` (that role or
  higher), model actions with policies in `app/Policies`, and other
  abilities with the gates in `AppServiceProvider` (`access-admin`,
  `review-uploads`).
- Failed logins are limited per matric number *and* IP (5 per minute), plus
  100 per IP per 10 minutes. Accounts are never locked, so nobody can lock a
  classmate out by guessing their password.
- Suspended accounts can't log in, and an active session ends on its next
  request.
- Accounts flagged `must_change_password` (temporary passwords) can only
  change their password or log out.
- **Forgot password:** a reset link goes to the account's *verified* email
  (add or change it in Account settings). Students without one get a
  one-time code from a course rep (their own class) or an admin at
  `/reset-codes`, and use it at `/reset-with-code`. Locally, every email
  lands in Mailpit at http://localhost:8025.
- **Two-step verification:** optional, in Account settings. Works with any
  authenticator app (TOTP); 8 recovery codes are shown once.

## Library

- `/library`: approved books in open departments, 24 per page, with search,
  level/department filters and sorting. Search uses MySQL's full-text index
  (every word must match, word starts count: "data struct" finds "Data
  Structures"); `App\Support\Catalog` holds the query.
- `/library/{public id}`: book page. Unapproved books are "not found" to
  everyone except the uploader and governors/admins (`BookPolicy`).
- Covers live on the private disk and are served by `BookCoverController`
  with a versioned URL, so browsers cache them for a year.
- `/saved`: the student's bookmarks. Saving works with or without
  JavaScript.

## Front end

- **Style guide:** http://localhost:8080/styleguide shows every token and
  component in light and dark mode (not available in production).
- **Tokens** live in `resources/css/tokens.css`. Use the semantic utilities
  (`bg-surface`, `text-muted`, `bg-primary`, …) so dark mode works for free.
- **Components** live in `resources/views/components`: `x-layouts.app`,
  `x-layouts.guest`, `x-button`, `x-card`, `x-alert`, `x-badge`, `x-field`,
  `x-icon`.
- **Icons** are a curated subset of [Lucide](https://lucide.dev) in
  `resources/icons`, rendered inline with `<x-icon name="book-open" />`. To add
  one, copy it from `node_modules/lucide-static/icons`.
- **Fonts** (Inter and Plus Jakarta Sans, Latin subset) are self-hosted from
  npm packages and bundled by Vite. Nothing loads from a third-party CDN.
- **Images:** the logo files in `public/images`, `public/favicon.ico` and
  `public/apple-touch-icon.png` are resized, compressed copies of
  `legacy/assets/images/NACOS_LOGO.png` (416 KB → 6–16 KB). Always give
  `<img>` a `width` and `height` so the page doesn't jump while it loads.
- **Motion:** `.animate-enter`, `.stagger` and `data-reveal`
  (`resources/css/motion.css`). Only `transform` and `opacity` are animated,
  and everything is instant when the device asks for reduced motion.
- **Content-Security-Policy:** only this site's own scripts, styles, fonts and
  images are allowed. Inline `<script>`/`<style>` blocks need
  `nonce="{{ Vite::cspNonce() }}"`, and inline `style="…"` attributes and
  `onclick=` handlers are blocked, so use classes and JavaScript modules.

## Architecture notes

- **Shared-hosting friendly:** sessions, cache and the job queue all use the
  database. The production cron runs `php artisan schedule:run` every minute,
  which also drains queued jobs (OCR, notifications).
- **Private files:** book PDFs and covers live on the `private` disk
  (`storage/app/private/library`) and are only ever streamed through
  controllers that check permissions. Nothing under `storage/` is
  web-accessible.
- **Strict models:** outside production, lazy loading (N+1 queries),
  mass-assigning unknown fields and reading missing attributes all throw, so
  performance and data bugs surface during development.
- **Elections:** who voted (`election_voters`) and what was voted
  (`election_votes`) are stored separately with no link between them, so
  ballots stay secret even to admins with database access.
- Times are stored in UTC and displayed in `Africa/Lagos`.

## Deployment

See [docs/deployment.md](docs/deployment.md).
