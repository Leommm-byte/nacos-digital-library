# UI and UX overhaul (PR 6.5)

An honest audit of every screen built so far, compared with the legacy UI,
and the plan that fixes it. Every later PR is held to the "Standard" section.

## What the legacy UI did better

- **Brand presence.** Auth pages sat on a bold green-to-yellow gradient;
  the dashboard opened with a deep green gradient hero and pill badges.
  You knew whose app it was. The rebuild's auth pages are a small card on
  a pale grey page.
- **A real home.** Three columns: profile summary, stats with coloured icon
  tiles, continue reading, recommendations, activity and announcements.
  The rebuild's signed-in home is still a placeholder ("What's coming").
- **Softer, friendlier surfaces.** Larger radii (16–24px), a tinted page
  background (`#eef4f9`) that made white cards lift, coloured icon tiles
  on stats and section headings.
- **Section headers with actions** ("Recommended for you … See all").

## What the legacy UI did worse (don't bring back)

- Four font families (Inter, Poppins, Plus Jakarta Sans, Outfit) from a
  third-party CDN; inline `style=""` everywhere; per-page colour overrides.
- Heavy shadows (`0 20px 50px`), `backdrop-filter` glass, hover lifts on
  everything: slow on cheap phones and visually noisy.
- Inconsistent spacing and buttons from page to page; no dark mode; weak
  focus states; tiny tap targets in places.

## Audit of the rebuild so far

| Area | Problem | Fix |
|---|---|---|
| Overall | Flat: white cards with hairline borders on a near-white page; little depth or hierarchy. | Tinted page background, slightly larger radii, one soft elevation level, coloured icon tiles. |
| Page headers | Every page hand-rolls its title, subtitle and actions, so sizes and spacing drift. | `x-page-header` (eyebrow, title, subtitle, actions, back link). |
| Sections | Cards stacked with ad-hoc `mt-6`/`mt-8`. | `x-section` with heading, description and optional action; one vertical rhythm. |
| Home (signed in) | Placeholder hero and a "What's coming" list. | Real home now: greeting by time of day, class badges, quick actions, recently added for your level, saved books, account health (email, two-step). Full dashboard stats still come in PR 10. |
| Home (guest) | Generic hero. | Proper landing: brand hero, what students get, sign up / log in. |
| Auth pages | Small card on grey, weak brand. | Split layout: green brand panel (logo, tagline, subtle pattern) beside the form on desktop; green header band on phones. |
| Header | Plain; nav text small; no visual anchor. | Taller, clearer active state, logo lock-up, search field on desktop instead of an icon. |
| Settings | Long stack of cards with forms jammed in. | Settings layout: section list (desktop) and grouped rows with label and help on the left, controls on the right. |
| Library cards | Placeholder covers are loud solid blocks that dominate the grid. | Quieter tinted covers with a subtle pattern, a NACOS mark and better type; consistent card metadata. |
| Book page | Fine structure; actions and metadata feel loose. | Tighter hero with cover shadow, action bar, metadata as a definition grid with icons. |
| Empty states | Hand-rolled each time. | `x-empty-state` (icon tile, title, text, action). |
| Feedback | Success messages appear as banners that push the page down. | Successes as toasts; banners only for errors and warnings. Buttons show a spinner and disable while submitting. |
| Motion | Only enter animations. | Cross-page view transitions (instant with reduced motion), pressed states, skeletons where data loads. |
| Footer | Bare line. | Small footer with links and the NACOS lock-up. |

## Standard for every PR from now on

- Built from the shared components (`x-page-header`, `x-section`,
  `x-empty-state`, `x-card`, `x-button`, `x-field`…); no one-off spacing.
- Checked in the real running app at 390px and 1280px, light and dark,
  with screenshots attached to the PR.
- Every screen has designed empty, loading and error states.
- Tap targets at least 44px; visible focus; text contrast WCAG AA.
- Motion only on `transform`/`opacity`, and none with reduced motion.
