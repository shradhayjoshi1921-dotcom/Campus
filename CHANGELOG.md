# Changelog

## Unreleased

### Added
- **Career intelligence, four phases.** A configurable scoring model (`config/scoring.php` + `app/Services/ScoringModel.php`) now drives an explainable career fit (skills / evidence / academic / role, with nulls dropped and weights re-normalised), skill-gap priorities that separate *claimed* from *demonstrated*, grouped recommendations where hard eligibility always wins, week-by-week roadmaps, a six-category readiness scorecard, a career graph and a what-if simulator that writes nothing. `public/career.php` was rewritten on top of them.
- **Resume intelligence.** `ResumeTextService` extracts text from the student's own PDF with a dependency-free parser and refuses encrypted, oversized or image-only files *with a reason*. `ResumeAnalysisService` extracts sections, compares them against one role with word-boundary matching and quoted context, and rewrites one bullet without inventing a metric. `public/resume-review.php` lets the student tick what should be added; approval records claims, never verified skills.
- **Practice.** `public/practice.php` runs text mock interviews against a five-criterion rubric (no voice, face or emotion analysis) and the assessments the placement cell publishes, with cautious levels ("Not enough evidence" below five questions) and the 60% demonstration line.
- **Recruiter AI tools** (`recruiter.php?section=ai`): draft a job description from pasted prose (unknown fields named, nothing published), build an interview kit from one of your own jobs, and compare an applicant's stored resume with that job's requirements. Read-only, and each resume read is audited as `resume.analysed`.
- **Institutional intelligence** (`admin.php?section=intelligence`): readiness by branch, employer skill demand with co-occurrence, training gaps ranked by affected students, employer coverage, monthly application volume that refuses to infer a trend from fewer than six periods, and records worth a human look. No student is named and every flag says it is a review prompt.
- **Optional AI service layer** (`app/Ai/*`): off by default (`AI_ENABLED=false`), hourly quotas, a 12 000-character payload cap, usage logs with no prompt text, and a deterministic rules fallback for every touchpoint.
- Three additive migrations (`skill_evidence`, `resume_analyses`, `ai_usage_logs`, `practice_sessions`, `practice_answers`, `career_targets`, roadmap task columns, assessment skill tag) with `scripts/migrate.php` (`--status`, per-statement duplicate tolerance, no fake transaction around DDL, a file recorded only after its last statement succeeds).
- A sixth student dock tab (**Career**) and a sixth recruiter tab (**AI tools**), plus a ninth admin tab (**Insights**). `tests/js/dock-geometry.test.js` now verifies 6 / 6 / 9 tabs and still fits at 360 px.
- `docs/CAREER-INTELLIGENCE.md` (what shipped, how to migrate, what was and was not verified) and `docs/SCORING.md` (every weight, the worked arithmetic, and what the numbers are not).
- Fourteen new tests in `tests/run.php` covering the scoring model, evidence levels, gap priority, roadmaps, what-if, readiness, resume analysis, the job-description drafter, the interview kit, assessments, the institution service, the career graph, the studio routing, and the CSRF/ownership/migration invariants (80 assertions total, all passing).

### Fixed
- **The intelligence layer no longer needs PHP 8.1.** Promoted `readonly` properties and `never` return types (both 8.1) are gone, so the whole application parses and runs on **PHP 8.0**, which is what a stock XAMPP 8.0 install provides. `bootstrap/app.php` now checks `PHP_VERSION_ID` before any other include and prints a readable "PHP upgrade required" page (and a one-line CLI error) instead of a bare parse error.
- `scripts/doctor.php` reports PHP version, required extensions, `.env`, database connection, which `schema.sql` tables are missing, pending migrations and whether an admin account exists — read-only, and it never prints a password.
- `index.php?page=career|resume-review|practice` redirects to the studio scripts instead of returning 404.

### Changed
- **LogicBloom "Light Ice Blue" theme.** `public/assets/css/logicbloom.css` re-points every existing token set (`--sv-*`, the legacy `--bg`/`--text`/`--mint` palette, and the `--rec-*`/`--adm-*` portal sets) at the ice palette and restyles the components whose geometry the palette changes. Loaded last on every page; no `!important`, no second styling system, no markup rewrite in the workspaces. Light only: both theme attributes resolve to the same tokens and the appearance switch is hidden.
- **Self-hosted Inter** (`public/assets/fonts/`, SIL OFL, 400-800, `font-display: swap`). No font CDN and no external request anywhere.
- **New landing page.** `public/assets/css/homepage.css` was rewritten for `public/index.php`: a two-column hero with the student workflow card, three role cards, a "how an opportunity reaches a student" band and a quiet footer — same copy, same links, same anchor ids.
- **`public/assets/js/theme-guard.js`** hides the floating dock if its own stylesheet never arrives, so a blocked or stale CSS request can never leave a wall of raw links over the page. The bubble menu stays as the fallback navigation.
- **Every inline `<svg>` now carries intrinsic `width`/`height`/`viewBox` and an explicit `fill`** (43 icons across the views), so a missing stylesheet degrades to small outlines instead of a large solid block.
- `meta[name="theme-color"]` is `#edf8fc`; the studio, recruiter and admin heads load the theme last like the public site.
- `public/career.php` is now a career studio with a shared shell (`public/views/partials/studio-start.php` / `studio-end.php`), so the student dock and its sign-out form exist in exactly one place. `public/views/partials/student-dock-markup.php` holds the six tabs used by both `index.php` and the studio.
- Recruiter dock labels move to screen-reader-only below 420 px, where six tabs leave ~55 px each.
- Two-theme design system for every portal: premium warm light orange (ivory paper, white cards, ember accent) and premium matte black (charcoal surfaces, silver glass, warm accent used sparingly). The teal/green palette, navy hero gradients and glowing orbs are removed.
- A hidden `#lg-refract` SVG filter (`public/views/partials/glass-defs.php`) so the glass pill's refracted interior is defined once per document instead of referencing a missing filter.
- `tests/js/student-nav.test.js` (7 jsdom scenarios) covering the in-place student page swap.
- Sign in and registration now share one two-panel entry layout: a role-aware product panel on the left (what the student, recruiter and administrator each get, plus a privacy line) and a focused form card on the right with a password reveal button, inline validation states and a role summary that reads the account's role rather than asking for it on sign-in.
- The auth submit button reports progress: it switches to "Signing in…" / "Creating account…", sets `aria-busy`, and turns enabled again if the page is restored from the back/forward cache.
- Recruiters can list **required and preferred skills** on a job. Both lists are parsed by one shared service (`app/Services/SkillListService.php`) and written to the existing `skills` / `job_skills` tables inside the job's transaction, so an existing spelling is reused instead of duplicated. This is what makes career fit, skill gaps, roadmaps and the what-if simulation real: `job_skills` was previously read by `career.php` and written by nothing.
- Students now see the skills a role asks for on the job board, and the roles they browse are tagged required vs preferred.
- Students can see **their own scheduled interviews and offers**, including the CTC a recruiter recorded, on the applications page and in the dashboard's Next steps panel.
- Recruiters can open a candidate's PDF resume from the applicant card. Access is scoped to the recruiter's own company and audited as `resume.viewed`; anyone else still gets a 404.
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
- The bottom navigation is now viewport-driven on every portal. One set of `--dock-*` tokens in `liquid-glass.css` (gutter, bottom offset, padding, gap, tab height, icon, label and badge, all `clamp()`) sizes the capsule, and `--dock-max` caps it per portal. No tab carries a fixed `min-width` any more, so five tabs divide the window they actually have and the eight-tab admin rail still fits at 360 px. Labels compact to screen-reader-only (admin ≤720 px, student ≤380 px, short viewports) instead of overflowing.
- The footer is no longer swallowed by the dock. The recruiter and admin footers were pulled up with `margin-top: -92px` / `-96px`, which put them inside the dock's band at every width; both now sit in normal flow and reserve `--dock-clearance`, derived from the real capsule height. The student canvas uses the same token instead of a fixed 140 px.
- Resizing and split-screen keep the active bubble aligned. Measurements are coalesced into one per frame (a window drag no longer triggers a measure per event), `visualViewport` is watched as well as `window`, and the pill's floor dropped to 28 px so it can never be wider than a 41 px admin tab.
- `tests/js/dock-geometry.test.js` resolves the declared tokens at 1536 / 1024 / 768 / 480 / 360 px and sweeps 1536→360 continuously, asserting the capsule fits, tabs stay usable, the pill stays inside its tab and every footer reserves the clearance.
- Removed the strip of bare canvas above the first row of chrome at its source: `html` and `body` now share one top colour, `body` is `display: flow-root`, `.bubble-top` uses padding instead of a collapsing top margin, and the dark canvas is pinned to `#0b0c0e` for overscroll.
- Removed the blanket `sv-page-in` animation from the student canvas: a finished CSS animation was out-ranking the states the swap sets, which made the old cross-fade inconsistent.
- Career fit no longer reports a perfect skill match for a job with no listed skills. `CareerFitService` used to score an empty requirement list as 100%, so every student "matched" every role with nothing to fix; the skill component is now reported as `null`, the estimate falls back to academics and role alignment, and the page says why.
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
