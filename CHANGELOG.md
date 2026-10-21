# Changelog

## Unreleased

### Added
- Two-theme design system for every portal: premium warm light orange (ivory paper, white cards, ember accent) and premium matte black (charcoal surfaces, silver glass, warm accent used sparingly). The teal/green palette, navy hero gradients and glowing orbs are removed.
- A hidden `#lg-refract` SVG filter (`public/views/partials/glass-defs.php`) so the glass pill's refracted interior is defined once per document instead of referencing a missing filter.
- `tests/js/student-nav.test.js` (7 jsdom scenarios) covering the in-place student page swap.
- Sign in and registration now share one two-panel entry layout: a role-aware product panel on the left (what the student, recruiter and administrator each get, plus a privacy line) and a focused form card on the right with a password reveal button, inline validation states and a role summary that reads the account's role rather than asking for it on sign-in.
- The auth submit button reports progress: it switches to "Signing in…" / "Creating account…", sets `aria-busy`, and turns enabled again if the page is restored from the back/forward cache.
- `tests/js/liquid-glass.test.js` gains a tap-vs-drag scenario and a retargeted-click scenario; `tests/js/student-nav.test.js` gains the same retargeted-click case; `tests/run.php` now asserts the pointer model and the shared auth layout.

### Changed
- The navigation glass now blurs what is actually behind it: the capsule and the pill use a 30 px / 20 px backdrop blur with a saturation and brightness lift, a 1.5 px meniscus rim that is bright at the top and lit again along the bottom bounce, an inner sheen across the upper third and an inner shadow along the lower wall. Light mode keeps a dark hairline so the glass still has an edge on cream paper.
- Docks are wider and taller with slightly larger icons (820 px capsule for the student and recruiter workspaces, 960 px for the eight-tab admin rail), so the active pill reads as one generous glass blob rather than a small box.
- Every text colour in `recruiter.css` and `admin.css` now reads from a `--rec-*` / `--adm-*` token instead of a dark-only hex literal, and the light theme remaps the tokens once. Status pills keep their meaning in light mode (green live, amber in progress, red closed out, neutral parked) instead of flattening to one grey.
- `public/index.php` renders the sign-in and registration pages without the page-top alert band; messages appear inside the form card, next to the field they belong to.
- Student tabs no longer reload the document. `assets/js/student-nav.js` fetches the destination, swaps only `<main id="main-content">`, keeps the dock mounted, pushes history and plays a direction-aware entrance, matching the recruiter and admin workspaces. Back/forward, modified clicks and any response that is not a student page fall back to a normal load.
- Rebuilt the navigation material as a wide, fully rounded glass capsule with a separate one-tab glass pill: graded translucent fill, displaced smoke, curved specular cap, masked rim, lower caustic and travelling glint, in both themes.
- Added curved refraction and a `scale3d` stretch/squash so the pill deforms like liquid instead of resizing its box; the engine writes one transform per frame and no longer touches layout mid-spring.
- Replaced the per-portal indicator scripts and styles with one shared Liquid Glass engine (`assets/css/liquid-glass.css`, `assets/js/liquid-glass.js`).
- Student, recruiter and admin docks now only mount the shared engine; each portal keeps its own routing, history, filters and swipe gestures and hands navigation back through `liquidglass:activate`.
- Admin job, placement and report status/year filters are `[data-glass-group]` option groups using the same material.
- The capsule stretches only within its own tab (stretch budget equals the tab inset), retargets a fixed 1/240 s spring on rapid switches, persists the last visited tab per portal and settles on the server-rendered destination after refresh, back or forward.
- Added `tests/js/liquid-glass.test.js` (16 jsdom scenarios) and extended `tests/run.php`; removed the obsolete per-portal dock test.

### Fixed
- Removed the strip of bare canvas above the first row of chrome at its source: `html` and `body` now share one top colour, `body` is `display: flow-root`, `.bubble-top` uses padding instead of a collapsing top margin, and the dark canvas is pinned to `#0b0c0e` for overscroll.
- Removed the blanket `sv-page-in` animation from the student canvas: a finished CSS animation was out-ranking the states the swap sets, which made the old cross-fade inconsistent.
- Tapping a dock tab now navigates. The engine captured the pointer on pointerdown, which made the browser retarget the follow-up click to the dock itself, so a press shorter than the drag threshold was swallowed while a drag still worked. The press is now tracked from `window` with no capture, and a click that is still retargeted falls back to `elementFromPoint`.
- Dragging from a tab still works and still suppresses the click that follows it for 260 ms.
- The landing page's role cards were white text on a white card: `.homepage .feature-card` now carries an explicit light palette with dark overrides, and the hero feature card's step titles and status line follow the same fix. `career.php`'s skill chips, error strip and input text were also authored light-on-dark and are now readable in the light theme.
- Light-mode text that was authored for the dark recruiter/admin portals (form labels, review-flow steps, table headers, muted notes) now follows the light ink tokens.
- `public/career.php` no longer ships a private navy/green stylesheet; its panels, pills and buttons use the shared tokens.
- Consolidated the conflicting dock ink/override rules into the shared material, and removed the dead `rec-dock` selectors plus the unused recruiter colour tokens.

## 2.0.0 — 2026-10-19

### Updated
- Replaced the scroll-blocking cinematic landing intro with a responsive, professional landing page.
- Added a locally bundled campus-at-dusk hero image and removed remote font, framework, and icon CDN dependencies from the main portal.
- Introduced consistent colors, spacing, responsive navigation, card and form styling, plus subtle scroll reveals and hover motion.
- Added reduced-motion support and keyboard-accessible mobile navigation.
- Updated XAMPP setup instructions and created a self-contained package workflow.

### Fixed
- Removed leftover merge-conflict markers from the admin bootstrap script, automated tests, and project documentation.
- Restored the admin setup script and test suite as valid PHP source files.
- Replaced outdated documentation about already-implemented portal workflows with the current feature scope and limitations.
