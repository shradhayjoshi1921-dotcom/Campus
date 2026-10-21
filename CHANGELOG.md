# Changelog

## Unreleased

### Added
- Two-theme design system for every portal: premium warm light orange (ivory paper, white cards, ember accent) and premium matte black (charcoal surfaces, silver glass, warm accent used sparingly). The teal/green palette, navy hero gradients and glowing orbs are removed.
- A hidden `#lg-refract` SVG filter (`public/views/partials/glass-defs.php`) so the glass pill's refracted interior is defined once per document instead of referencing a missing filter.

### Fixed
- Removed the strip of bare canvas above the first row of chrome at its source: `html` and `body` now share one top colour, `body` is `display: flow-root`, `.bubble-top` uses padding instead of a collapsing top margin, and the dark canvas is pinned to `#0b0c0e` for overscroll.
- `public/career.php` no longer ships a private navy/green stylesheet; its panels, pills and buttons use the shared tokens.
- Consolidated the conflicting dock ink/override rules into the shared material, and removed the dead `rec-dock` selectors plus the unused recruiter colour tokens.

### Updated
- Rebuilt the navigation material as a wide, fully rounded glass capsule with a separate one-tab glass pill: graded translucent fill, displaced smoke, curved specular cap, masked rim, lower caustic and travelling glint, in both themes.
- Added curved refraction and a `scale3d` stretch/squash so the pill deforms like liquid instead of resizing its box; the engine writes one transform per frame and no longer touches layout mid-spring.
- Replaced the per-portal indicator scripts and styles with one shared Liquid Glass engine (`assets/css/liquid-glass.css`, `assets/js/liquid-glass.js`): a neutral, one-tab-wide capsule with a smoky body, additive specular cap, masked refraction rim, lower caustic and travelling glint.
- Student, recruiter and admin docks now only mount the shared engine; each portal keeps its own routing, history, filters and swipe gestures and hands navigation back through `liquidglass:activate`.
- Admin job, placement and report status/year filters are `[data-glass-group]` option groups using the same material.
- The capsule stretches only within its own tab (stretch budget equals the tab inset), retargets a fixed 1/240 s spring on rapid switches, persists the last visited tab per portal and settles on the server-rendered destination after refresh, back or forward.
- Added `tests/js/liquid-glass.test.js` (14 jsdom scenarios) and extended `tests/run.php`; removed the obsolete per-portal dock test.

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
