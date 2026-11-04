# Campus — Aurora Liquid Glass

The portal's appearance is one stylesheet: `public/assets/css/aurora.css`, loaded
last on every page (public site, student workspace, career studio, recruiter
workspace, TPO/admin workspace). It declares two complete themes and re-points
the design tokens the project already used, so nothing had to be rebuilt to
follow it.

## Principles

1. **Two themes, both real.** Arctic Aurora (light) and Midnight Ocean (dark) are
   complete palettes, not a tinted variant. Midnight Ocean is genuine near-black
   and navy, never grey.
2. **Glass where it floats.** Blur belongs to the top chrome, the dock, sheets and
   the auth card. Cards get translucent fill, a white hairline and a blue-tinted
   shadow — cheap to paint, and the canvas behind them is a smooth gradient, so a
   blur would be invisible anyway.
3. **One accent family.** Electric cyan carries the brand; a deeper cyan carries
   white text in light, and the bright cyan carries a near-black label in dark.
4. **Two type treatments.** Headings carry negative tracking; only the 10 px
   eyebrows are uppercase with positive tracking. Weight stops at 800.
5. **One motion family.** One easing curve, three durations, transform and opacity
   only, always browser-driven.
6. **No second styling system.** No framework, no CSS-in-JS, no markup rewrite in
   the workspaces.

## Palette

### Arctic Aurora (light)

| Group | Token | Value |
|---|---|---|
| Canvas | `--au-bg-1` / `--au-bg-2` / `--au-bg-3` | `#EAF7FF` / `#DDF2FF` / `#F7FBFF` |
| Ink | `--au-ink` / `--au-ink-body` / `--au-ink-muted` | `#0A2540` / `#1B3B57` / `#476583` |
| Heading | `--au-ink-heading` | `#0A2540` |
| Eyebrow | `--au-kicker` | `#0B6E93` |
| Accent | `--au-accent` / `--au-accent-2` | `#0EA5E9` / `#38BDF8` |
| Filled action | `--au-accent-fill` / `-hover` / `--au-accent-ink` | `#0670A8` / `#055C8C` / `#FFFFFF` |
| Accent text | `--au-accent-deep` / `--au-accent-soft` | `#075985` / `#E0F2FE` |
| Link | `--au-link` / `--au-link-hover` | `#0B6FA4` / `#075985` |
| Focus | `--au-focus` | `#0284C7` |
| Glass | `--au-glass` / `--au-glass-strong` / `--au-glass-solid` | `rgba(255,255,255,.62)` / `.84` / `#FFFFFF` |
| Hairline | `--au-hair` / `--au-hair-strong` | `rgba(10,37,64,.10)` / `.18` |
| Sunken | `--au-sunken` | `#E5F2FC` |

### Midnight Ocean (dark)

| Group | Token | Value |
|---|---|---|
| Canvas | `--au-bg-1` / `--au-bg-2` / `--au-bg-3` | `#030712` / `#071426` / `#0B1930` |
| Ink | `--au-ink` / `--au-ink-body` / `--au-ink-muted` | `#EAF6FF` / `#C3D6E9` / `#9FB4CC` |
| Eyebrow | `--au-kicker` | `#7DD3FC` |
| Accent | `--au-accent` / `--au-accent-2` | `#38BDF8` / `#3B82F6` |
| Filled action | `--au-accent-fill` / `-hover` / `--au-accent-ink` | `#38BDF8` / `#5ECBFB` / `#04121F` |
| Glass | `--au-glass` / `--au-glass-strong` / `--au-glass-solid` | `rgba(13,28,50,.58)` / `.82` / `#0B1930` |
| Hairline | `--au-hair` / `--au-hair-strong` | `rgba(148,197,255,.14)` / `.26` |
| Sunken | `--au-sunken` | `#081627` |

Shared: radii 26 / 20 / 14 / 10 / 999 px, easing
`cubic-bezier(.22,1,.36,1)` with 160 / 260 / 420 ms, max width 1380 px, gutters
80 → 20 px.

## The project's own tokens

One alias block re-points everything the codebase already used. This is why no
search-and-replace was needed in 5 000 lines of component CSS:

| Token set | Declared in | Drives |
|---|---|---|
| `--sv-*` | `theme.css` | bubble chrome, sheets, auth, dock shell |
| `--bg` / `--text` / `--line` / `--mint` | `app.css` | panels, buttons, forms, tables, dashboard |
| `--rec-*` | `recruiter.css` | recruiter workspace |
| `--adm-*` | `admin.css` | TPO / admin workspace |
| `--lg-*` | `liquid-glass.css` | the dock's glass layers |

## How the cascade works

The older stylesheets carry 250+ rules scoped
`html[data-theme="light"] .thing` and `html[data-theme="dark"] body.portal .thing`.
Component rules here are scoped `html[data-theme] …`, which has **equal**
specificity, so the later file wins on source order. Where the legacy rule added a
second class step (`body.recruiter-portal .thing`), the Aurora rule repeats that
step exactly.

There is no `!important` anywhere except the `prefers-reduced-motion` block, where
overriding transitions is the whole point. `tests/js/aurora-cascade.test.js`
parses all six stylesheets in load order, resolves the winner for 29 real
elements × 2 themes, and fails if a legacy sheet wins any material declaration.

## Motion

- **Real page loads** use a cross-document view transition. The dock and the top
  chrome carry `view-transition-name`, so they hold still while the canvas
  cross-fades: 180 ms out, 320 ms in.
- **In-workspace navigation** never reloads. The three nav scripts fetch the
  destination, swap the content region and animate it in (340 ms) with a direction
  from the address bar; browser back and forward run the same path.
- **Micro-interactions**: cards lift 3 px, buttons settle 1.5 % on press, the pill
  in the dock stretches with velocity, inputs glow with a 3 px cyan ring on
  `:focus-visible` only.
- `prefers-reduced-motion: reduce` collapses all of it; `prefers-contrast: more`
  swaps the glass fills for solid surfaces.

Nothing polls a timer — every frame comes from the compositor, so a 120 Hz or
240 Hz display runs the animation at its own cadence.

## Dock

Material only. Geometry (6 / 6 / 9 tabs, `--dock-*` from `liquid-glass.css`, the
1536 → 360 px sweep) is untouched; `aurora.css` re-points the `--lg-*` glass and
the link colours for both themes. The engine (`assets/js/liquid-glass.js`) owns the
spring: a critically damped 300/27 move, stretch with velocity, a little squash at
speed, and the pill never leaves its own tab.

## Type

Inter, self-hosted in `public/assets/fonts/` (SIL OFL 1.1, weights 400–800,
`font-display: swap`, licence alongside).

| Role | Size | Weight | Tracking |
|---|---|---|---|
| Page hero `h1` | `clamp(28px, 3.2vw, 42px)` | 760 | `-.058em` |
| Section `h2` | 18px | 740 | `-.042em` |
| Card `h3` | 14px | 720 | `-.022em` |
| Eyebrow | 10px uppercase | 800 | `.14em` |
| Body | 13px / 1.65 | 400–500 | normal |

Numbers in tables, stat cards and meters use tabular figures so columns line up.

## How to change the palette

Everything derives from the token blocks at the top of `aurora.css`. Change the
`--au-*` values in both themes, then run `php tests/run.php`: it recomputes WCAG
contrast for 39 pairs (both themes, text and graphics) and fails if any drops
below its threshold.

## What was verified here, and what was not

**Verified**

- `php tests/run.php` — **81 tests, 0 failed**, including both palettes, the
  pre-paint appearance bootstrap, the load order, the guard, undeclared tokens,
  stray `!important` / `100vw` / external URLs / emoji, blur discipline, motion
  invariants, the self-hosted fonts, and 39 computed WCAG pairs (17 light text,
  15 dark text, 7 graphics — lowest 4.51:1 for text, all graphics ≥ 3:1).
- `node tests/js/aurora-cascade.test.js` — 130 cascade resolutions across both
  themes; no legacy stylesheet wins a material declaration.
- `node tests/js/dock-geometry.test.js` / `liquid-glass.test.js` /
  `student-nav.test.js` — the dock still fits and still works in 15 viewport and
  portal combinations.
- Every stylesheet parses with `css-tree` (`aurora.css` 289 rules,
  `homepage.css` 95 rules, 0 errors).
- Server-side renders with no PHP warnings: home, login, register, dashboard,
  profile, jobs, applications, notifications, recruiter overview, and the studio
  shell.
- Class audit: every class used in the rendered home, login and dashboard markup
  is defined in a stylesheet.

**Not verified**

- **No browser in this environment.** Colours, contrast and the cascade were
  checked by computation, not by looking at pixels. Walk the pages in both themes
  before shipping.
- `admin.php` type-hints `PDO` and this sandbox has no MySQL, so it could not be
  rendered; its rules were checked statically.
- The dock's labels keep their existing wording (Home / Jobs / Career /
  Applications / Alerts / Profile). They describe real routes; renaming them to a
  design-brief vocabulary would have been a navigation change, not a re-skin.
- No asset prefetching was added: the in-workspace fetch already warms the next
  section, and pre-loading pages a visitor may never open would cost more than it
  saves.
- 360 px `scrollWidth` and axe-core were not run (no browser or assistive tech).
