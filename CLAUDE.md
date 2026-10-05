# Project guide

NACOS YabaTech student portal, being rebuilt on Laravel 12 (PHP 8.3, MySQL 8)
for DreamHost shared hosting. Read `docs/roadmap.md` first: it holds the plan,
the decisions already made with the owner, and PR status.

- `legacy/` is the original app, kept only as a reference. Never serve or
  extend it.
- Before pushing, run `vendor/bin/pint`, `vendor/bin/phpstan analyse` and
  `php artisan test`. CI runs the same checks, with tests on MySQL 8.
- Local stack: `docker compose up -d --build` (app on :8080, Mailpit on :8025).
- Shared hosting constraints: no Redis, no long-running workers, no
  WebSockets. Use the database drivers and the cron-driven scheduler.
- Models are strict outside production; fix N+1 queries with eager loading
  instead of relaxing strict mode.
- Do not add links between `election_voters` and `election_votes`.
- Front end: use the semantic colour tokens (`bg-surface`, `text-muted`,
  `bg-primary`…) and the Blade components in `resources/views/components`;
  check new UI in light and dark mode on `/styleguide`.
- The Content-Security-Policy blocks inline `style=""` attributes, inline
  event handlers and third-party hosts. Inline `<script>`/`<style>` blocks
  need `nonce="{{ Vite::cspNonce() }}"`. Self-host every asset.
- Animate only `transform` and `opacity`; `prefers-reduced-motion` must still
  work. No `backdrop-filter` or large shadows.
- Authorisation: `role:<role>` route middleware for "this role or higher",
  policies in `app/Policies` for models, gates in `AppServiceProvider` for
  the rest. Never compare `$user->role` by hand in controllers or views.
- `docs/legacy-checklist.md` lists every legacy behaviour; tick off what a
  PR delivers and keep its "Fix" items from coming back.
