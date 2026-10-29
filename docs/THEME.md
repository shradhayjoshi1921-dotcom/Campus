# LogicBloom — the "Light Ice Blue" theme

The portal's appearance is one stylesheet: `public/assets/css/logicbloom.css`.
It loads last on every page (public site, student workspace, career studio,
recruiter workspace, TPO/admin workspace) and re-points the design tokens the
project already used, so nothing has to be rebuilt to follow it.

## Principles

1. **Soft ice blue and white.** A pale cyan canvas, frosted white surfaces, wide low-opacity
   shadows, generous radii (12–26 px). No pure black, no saturated blue, no neon.
2. **One accent.** Slate teal `#2F7291` (deep `#075985`) for actions, links and active states.
   `#0284C7` appears only in focus rings.
3. **Two type treatments.** Headings carry negative tracking; only the 10 px eyebrows are
   uppercase, with positive tracking. Weight never exceeds 800.
4. **Light only.** Both `html[data-theme]` values resolve to the same tokens, so a stored
   preference cannot paint a half-dark page. The appearance switch is hidden.
5. **No second styling system.** No framework, no CSS-in-JS, no rebuild. Tokens plus
   component rules, loaded last.

## Tokens

| Group | Token | Value |
|---|---|---|
| Canvas | `--lb-bg-1` / `--lb-bg-2` / `--lb-bg-3` | `#EDF8FC` / `#E4F2F8` / `#F2F9FC` |
| Tint | `--lb-tint` | `rgba(189,225,241,.58)` |
| Ink | `--lb-ink` / `--lb-ink-body` / `--lb-ink-muted` | `#19394C` / `#405F70` / `#446575` |
| Headings | `--lb-ink-strong` / `--lb-ink-heading` | `#17384B` / `#1A3B4D` |
| Kicker | `--lb-ink-kicker` | `#2F6680` |
| Accent | `--lb-accent` / `--lb-accent-hover` / `--lb-accent-deep` | `#2F7291` / `#286C8D` / `#075985` |
| Links | `--lb-link` / `--lb-link-hover` | `#23647F` / `#144C68` |
| Glass | `--lb-surface` / `--lb-surface-strong` / `--lb-blur` | `rgba(255,255,255,.77)` / `.92` / `blur(24px) saturate(145%)` |
| Shadows | `--lb-shadow-card` / `-hover` / `-hero` / `-button` / `-float` | see the file |
| Radii | `--lb-r-hero` / `-card` / `-tile` / `-control` / `-pill` | 24 / 18 / 13 / 12 / 999 px |
| Motion | `--lb-ease` / `--lb-t-fast` / `--lb-t-slow` | `cubic-bezier(.22,1,.36,1)` / `.18s` / `.38s` |

The project's own tokens are re-pointed at these values, which is why every
existing component follows without being rewritten:

| Token set | Declared in | Used by |
|---|---|---|
| `--sv-*` | `theme.css` | bubble chrome, dock materials, auth screens, sheets |
| `--bg` / `--text` / `--line` / `--mint` … | `app.css` | panels, buttons, forms, tables, dashboard |
| `--rec-*` | `recruiter.css` | recruiter workspace |
| `--adm-*` | `admin.css` | TPO / admin workspace |

## Type

Inter, self-hosted in `public/assets/fonts/` (SIL OFL 1.1, weights 400–800,
`font-display: swap`, licence in `Inter-OFL.txt`). No font CDN: the portal renders
identically offline.

| Role | Size | Weight | Tracking |
|---|---|---|---|
| Page hero `h1` | `clamp(27px, 3.1vw, 40px)` | 740 | `-.06em` |
| Section `h2` | 18px | 750 | `-.045em` |
| Card `h3` | 14px | 750 | `-.025em` |
| Eyebrow | 10px, uppercase | 800 | `.13em` |
| Body | 13px / 1.65 | 400–500 | normal |
| Note | 11–12px | 500 | normal |

## How to change the palette

Everything derives from the token block at the top of `logicbloom.css`. Change
`--lb-accent` and `--lb-accent-hover` together, then re-run `php tests/run.php`:
it recomputes WCAG contrast for the pairs that matter and fails if any drops
below 4.5:1. The foreground/background pairs are listed in the
`Text meets WCAG AA contrast on the ice palette` test.

## Load order

```text
theme.css → app.css → homepage.css (landing only) → water-dock.css (student)
→ liquid-glass.css → logicbloom.css
```

Component rules in `logicbloom.css` carry an `html[data-theme]` prefix so they tie
with, and therefore beat, `app.css`'s `html[data-theme="light"] …` overrides by
coming later in the cascade. There is no `!important` anywhere in the theme, except
inside the `prefers-reduced-motion` block, where overriding the transitions is the
whole point.

The landing page has its own stylesheet, `homepage.css`, scoped to
`body.homepage`. It reads the same tokens and carries a fallback for each one, so
it still lays out correctly if `logicbloom.css` is blocked.

## Safety net — `theme-guard.js`

If the theme stylesheet or the dock's stylesheet never arrives (blocked proxy,
stale cache, a wrong path), the guard:

1. probes `--lb-accent` on `:root` to learn whether the theme applied;
2. probes the dock with a live element to learn whether its stylesheet applied;
3. hides the dock, removes its bottom clearance, and marks the element with
   `data-theme-guard` — the bubble menu is plain markup, so navigation survives.

Every inline `<svg>` also carries intrinsic `width`, `height` and `viewBox`
attributes, so a missing stylesheet degrades to a small outline instead of a
300 × 150 solid block.

## What was verified here, and what was not

**Verified**

- `php tests/run.php` — **80 tests, 0 failed**, including new ones for the palette,
  the load order, the guard, the self-hosted fonts, undeclared tokens and stray
  `!important`/`100vw`/external URLs, and computed WCAG contrast for 20 text pairs
  and 4 graphic pairs, each against every canvas layer *and* the darkest stop of
  the landing hero gradient (text all ≥ 4.5:1, lowest 4.74:1; graphics all ≥ 3:1).
- Every stylesheet parses with `css-tree`; `logicbloom.css` is 138 rules and
  `homepage.css` 83 rules, both with 0 parse errors.
- `tests/js/` (dock geometry across 15 viewport/portal combinations, liquid-glass
  in 17 scenarios, student navigation in 7) still passes — the dock's geometry was
  not touched.
- Every themeable page renders server-side with no PHP warnings: home, login,
  dashboard, profile, jobs, applications (student), recruiter overview.
- Class audit: every class used in the rendered home, login and dashboard markup
  is defined in a stylesheet (no orphan classes).

**Not verified**

- **No browser in this environment.** Palette, rhythm and contrast were checked by
  computation and by reading the cascade, not by looking at pixels. Load the site
  and walk the pages before shipping.
- `admin.php` could not be rendered here: it type-hints `PDO` and this sandbox has
  no MySQL, so only the CSS rules that drive it were checked.
- The dock's five-tab LogicBloom variant from the design brief was **not** built.
  See the pull request for why: the product already ships portal-specific docks
  (6 / 6 / 9 tabs) with a tested responsive geometry, and replacing them would
  have been a navigation change, not a re-skin. The dock keeps its behaviour and
  takes the new material.
