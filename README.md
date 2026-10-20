# Campus — Placement & Career Intelligence

**Version 2.0.0** · PHP 8.2+ · MySQL/MariaDB · Apache/XAMPP

Campus is a server-rendered placement portal for students, recruiters, and placement teams. The interface uses an institute-focused navy-and-emerald design, responsive navigation, restrained frosted-glass accents, and reduced-motion-aware transitions. The homepage uses a CSS-built abstract hero rather than unverified campus photography. The website does not depend on remote font, Bootstrap, icon, or hero-image CDNs.

All three workspaces share one liquid-glass system: a translucent, neutrally tinted 3D glass capsule that sits behind the active destination in every dock and behind the active option in every segmented filter group. It is one shared component (`assets/css/liquid-glass.css` + `assets/js/liquid-glass.js`), one tab wide, driven by a fixed-step spring. The database starts empty. No fake users, companies, jobs, applications, or placement statistics are seeded.

### Recruiter navigation interaction

- Tap or click a tab (Overview, Company, Jobs, Applicants, Interviews) to glide the capsule to it.
- On desktop, press and drag horizontally anywhere on the dock: the capsule follows the pointer, stretches into the direction of travel, squashes slightly to conserve volume, and settles with a short spring. Release over a tab to open it. A plain click still opens the tab normally.
- On touch screens, swipe the dock left or right, or swipe the section content horizontally, to move to the next or previous section. Vertical scrolling is unaffected.
- A horizontal trackpad swipe over the section content also moves one section at a time.
- Arrow keys move focus between the five tabs; Enter opens the focused tab.
- Sections load in place over `recruiter.php?section=…`, so browser back/forward, direct links, and GET filters keep working. Any failed or unexpected request falls back to a normal page load.
- `prefers-reduced-motion: reduce` disables the spring, stretch, deform, and section slide while keeping every destination reachable.

The capsule is built from stacked CSS layers rather than an image: a smoky translucent body with `backdrop-filter`, an additive specular cap, a masked 1px refraction ring, a lower caustic and a travelling glint. A small SVG turbulence/displacement filter gives the interior its optical wobble and is dropped while the capsule moves, so no filter work happens on spring frames. `liquid-glass.js` owns the width, position and `--lg-slosh` / `--lg-stretch` custom properties; no layout property and no image is written per frame. Browsers without `backdrop-filter` get a denser glass fill so labels keep their contrast.

### Appearance: orange water, light and dark

The whole portal now runs on one appearance layer, `public/assets/css/theme.css`, loaded before every portal stylesheet.

- **Light is the default:** warm white paper (`#fff8f1`) with SVIET orange (`#ef6a1c`) accents and peach-glass navigation, cards and docks.
- **Dark is deep water:** teal-black surfaces (`#051519`) with ember-orange accents, so it reads as dark water rather than the old navy dashboard.
- **One switch everywhere:** a sun/moon button in the public header, recruiter topbar and admin topbar. The choice is stored in `localStorage` as `sviet-theme` and applied by a small inline bootstrap before the first paint, so neither theme flashes. `public/assets/js/theme.js` owns the toggle, the `aria-pressed` state and the `theme-color` meta tag. With no stored choice the page opens light; following the OS preference is available in the script for anyone who prefers it.
- The homepage hero is now a single sheet of orange water (three slow CSS droplets behind the copy), with the same treatment in dark as deep teal water. Login and registration sit on a shallow water pool.

### Theme 3.0 — Ember & Slate, floating bubbles, water dock

- **Palette:** warm ivory paper (`#faf7f3`), slate ink (`#1f2429`) and a single ember accent (`#cf5a17`); success/warning/danger are desaturated to sit quietly beside the accent. Dark mode is a cold slate pool (`#0d1114`) with the same ember. All values live as tokens in `theme.css`, so both themes come from one source.
- **No top bar anywhere.** Public pages, the recruiter workspace and the control centre now render a `bubble-top` cluster: a brand bubble on the left and a right-hand cluster of circular glass bubbles (appearance, account, sign-out). Public pages add one Menu bubble that opens a watery sheet; the sheet traps nothing, closes on Escape/backdrop, restores focus and locks body scroll.
- **One liquid-glass capsule on all three portals.** The capsule is one tab wide (inset 4–8 px from the tab), a rounded capsule rather than a teardrop, and sits behind the icon and label instead of covering neighbours. It is a child of the dock shell rather than the scrolling rail, so nothing clips it; the rail's `scrollLeft` is subtracted on every frame and scroll event. The shell stays its own subtle translucent container — the whole bar is never made into one bubble. The same capsule geometry and material drive the admin job/placement/report filter chips through `.lg-group`.
- **Motion tuned for high-refresh screens.** Springs integrate on a fixed 1/240 s step so 60 Hz, 120 Hz and 240 Hz panels resolve the same curve; only `transform`, `opacity` and two custom properties are written per frame, never layout properties. Page shifts use the View Transitions API where available (`@view-transition { navigation: auto; }`) with a short eased fade/rise fallback via `sv-page-in`. Shared easing tokens (`--sv-ease`, `--sv-dur-*`) replace ad-hoc curves, and every transition collapses under `prefers-reduced-motion`.

### One sign-in for every role

`index.php?page=login` is the only sign-in form. After the password check the server reads the account role and answers with a 303 redirect: students to `index.php?page=dashboard`, recruiters to `recruiter.php`, TPO/admin staff to `admin.php`. The login page says so and lists the three audiences; only the registration form still asks for an account type.

### Student workspace additions

- **PDF resume upload** (`upload_resume`): signature-checked (`%PDF-`), MIME-checked, `.pdf`-only, 5 MB cap, stored in `storage/uploads/resumes/` (outside the document root, which `.htaccess` also blocks). The newest upload replaces the previous file; `delete_resume` removes it. Downloads stream through `index.php?page=resume&id=…` — the owning student or TPO/admin only, with `Content-Type: application/pdf`, `X-Content-Type-Options: nosniff` and `no-store`.
- **Profile strength:** a six-point checklist (academic details, CGPA, phone, intro, skills, resume) rendered as a percentage meter on the dashboard.
- **Application timeline:** every `application_status_history` transition for the student, with the note and timestamp.
- **Notifications inbox** (`?page=notifications`): unread styling, unread count on the dashboard and a dock badge, plus "mark all read".
- **Student dock:** five destinations (Home, Jobs, Applications, Alerts, Profile) on the same spring-driven water capsule as the other portals. Dragging pans the rail and previews the tab under the pointer; the capsule flows in from the previous workspace via `sessionStorage` so arrival still reads as one continuous element.

### Recruiter CTC on selection

When a recruiter moves an application to **selected**, the applicant form now accepts an optional CTC in LPA (0–500, validated). The server upserts the matching `offers` row (`status = offered`, `offer_date = CURDATE()`, `created_by`), audits `offer.created` / `offer.updated`, and the student's notification names the recorded CTC. If the `offers` table has not been migrated the pipeline still works and no offer row is written.

### Liquid Glass system (shared)

- **One engine, three docks, three filter groups.** `public/assets/js/liquid-glass.js` exports `LiquidGlass.mount({ bar, track, links, indicator, restoreKey })`, `mountGroup(element)` for `[data-glass-group]`, `refresh(scope)` after a DOM swap and `controller(element)`. `student-nav.js` is a mount shim; `admin-nav.js` and `recruiter-nav.js` keep their own fetch-based section routing, form/filter handling and swipe gestures, and simply listen for `liquidglass:activate` when a drag or keyboard selection asks the page to navigate.
- **Neutral material, both themes.** The glass is smoky neutral — pale transmission and a white rim in light mode, blue-grey smoke over dark slate in dark mode — with no orange or brown tint. Labels keep full-contrast ink and every highlight is a white/blue-white light, not a colour wash.
- **One-tab geometry.** The capsule is trimmed by the tab inset and its stretch budget is capped at that inset, so even mid-flight it stays inside a single tab and never paints over a neighbouring label (links render above it). Docks keep their five / eight / five destinations; option groups keep their real filters.
- **Motion.** Springs integrate on a fixed 1/240 s step, so 60, 120 and 240 Hz panels resolve the same curve. The capsule leads with its leading edge, squashes slightly, sloshes its internal highlights and settles without overshoot flicker; rapid switches retarget the same spring instead of restarting it. Click, keyboard (arrows/Home/End), pointer drag and touch swipe all move the one object; `prefers-reduced-motion` moves it instantly with no stretch.
- **State survives navigation.** Active state comes from server-rendered `aria-current="page"`, and the last visited index is stored per portal in `sessionStorage` (`sviet-student-dock-index`, `sviet-admin-nav-index`, `sviet-recruiter-nav-index`) so the capsule flows in from the previous workspace and settles on the route after refresh, back or forward.
- **Degradations.** Without JavaScript the active destination keeps a quiet glass-tint fallback pill and navigation is plain links; without `backdrop-filter` the capsule gets a denser fill. Ordinary buttons, forms and destructive actions are untouched.

### Admin / TPO control centre

`admin.php` is the placement cell workspace. It reuses the same sessions, roles, CSRF token and `app.css` theme as the rest of the portal, and adds `assets/css/admin.css` and `assets/js/admin-nav.js`.

- **Eight sections:** Overview, Approvals, Students, Placements, Jobs, Reports, Comms and Governance. The dock is a horizontally scrollable rail, so every destination stays visible on a phone instead of being hidden behind an overflow menu. The active indicator is the same layered liquid-glass capsule as the recruiter dock — a masked 1px refraction ring, a top specular band, a lower rim bounce and a caustic blob — driven by a spring (`admin-nav.js`). Dragging the rail pans the track, previews the tab under the pointer, and releases onto the nearest one; swiping the section content moves one section at a time.
- **Approvals with reasons:** each decision (approve / request changes / reject / suspend / reactivate) writes `audit_logs`, updates the account, and sends the note to the applicant as a notification. Anything older than seven days is flagged. Companies are reviewed the same way, and bulk approve-all is available for a verified batch.
- **Bulk import:** `app/Services/AdminImportService.php` parses a CSV in memory (300 rows / 512 KB cap, quoted fields, BOM, common header aliases such as `Full Name` or `E-Mail`), validates email, role, company, CGPA (0–10) and graduation year (2000–2100), flags duplicate emails inside the file, and never touches the database. The parsed rows are staged in the session and shown as a review table; only **Import** creates accounts, hashes a fresh 14-character temporary password per row, and returns a one-time credential sheet as a CSV download. Existing emails are skipped, and each new account receives a welcome notification.
- **Offer tracking (opt-in migration):** run `database/migrations/2026-10-20-admin-portal-offers.sql` (also appended to `database/schema.sql`) to add the `offers` table — one offer per application, `ctc_lpa`, `offer_date`, `status` (`offered`/`accepted`/`declined`/`joined`/`revoked`) and `joined_on`. Until the table exists, Placements shows the migration hint and the rest of the portal keeps working. Placement percentage counts **joined** offers only; median and highest CTC use accepted and joined offers; students without offers stay in the denominator. Exports: `admin.php?section=reports&export=students|offers|branch`.
- **Honest empty states:** nothing in the workspace fabricates students, companies or package numbers; an empty database renders empty tables and zeroed stats.

## Run on Windows with XAMPP

1. Download and extract `Campus-XAMPP-v2.0.0.zip` into `C:\xampp\htdocs\Campus`. The archive includes a top-level `Campus` folder.
2. Start **Apache** and **MySQL** in the XAMPP Control Panel.
3. Open `http://localhost/phpmyadmin/` and import `Campus\database\schema.sql`. It creates the `ai_campus_portal` database and its tables. It does not migrate existing columns; back up an existing database before importing or changing the schema.
4. Copy `Campus\.env.example` to `Campus\.env`. Check the database settings and set a unique `APP_KEY`. Keep `APP_TIMEZONE` and `DB_TIMEZONE` aligned with the campus time zone so interview and job-deadline times display consistently. The `.env` file is intentionally not included in the ZIP or Git.
5. Open Command Prompt in `C:\xampp\htdocs\Campus` and create the first administrator, if one does not already exist:

   ```bat
   C:\xampp\php\php.exe scripts\create-admin.php "Portal Admin" admin@example.com "Use-A-Unique-Strong-Password"
   ```

   Use your own unique password of at least 14 characters. The bootstrap command works only when no admin exists. Avoid running it on a shared machine with a real password in command history.
6. Visit **http://localhost/Campus/public/**.

XAMPP must have PHP 8.2+, PDO MySQL, `mbstring`, and JSON enabled. Composer is not required to run the current application.

### Safer Apache configuration

For deployment, set the Apache document root to `Campus/public/`. If using the local `/Campus/public/` URL above, the included root `.htaccess` blocks direct web access to `.env` and internal project folders when Apache `AllowOverride` is enabled. Do not expose development credentials or personal student data to the public internet. Use HTTPS, a least-privilege database account, secure session cookies, and `APP_DEBUG=false` outside a local development environment.

## Main pages

- Portal home: `http://localhost/Campus/public/`
- Sign in / register: `http://localhost/Campus/public/index.php?page=login` / `?page=register`
- Student profile, jobs, and applications: available after student sign-in
- Career insights: `http://localhost/Campus/public/career.php` (student sign-in required)
- Recruiter workspace: `http://localhost/Campus/public/recruiter.php` (recruiter sign-in required)
- TPO/Admin workspace: `http://localhost/Campus/public/admin.php` (TPO or admin sign-in required)

## Current scope

- Student and recruiter registration/sign-in, session security, and CLI-only first-admin setup.
- Student profile and skills editing; published job browsing, eligibility checks, applications, and application tracking.
- Recruiter company review, approved-company direct job publishing with T&P moderation, company-owned job management, an applicant pipeline with history/notifications, and interview scheduling, rescheduling, cancellation, and completion.
- TPO/Admin account and company decisions, job moderation, summaries, reports, announcements, notifications, and audit activity.
- Rule-based career-fit estimates, skill-gap views, roadmap suggestions, and what-if skill simulation. These are explainable heuristics, **not trained AI or hiring probabilities**.
- Empty states are used instead of fabricated portal data.

## UI update in 2.0.0

- Replaced the scroll-locked cinematic intro with a standard, accessible landing page that never traps scrolling.
- Added a locally bundled campus-at-dusk hero background so the main visual works offline in XAMPP.
- Added a responsive navigation menu, clearer calls to action, refreshed workspace forms/cards/tables, and subtle scroll-reveal/hover motion.
- Honors the device's `prefers-reduced-motion` setting and avoids external style/font/icon CDNs.
- Added an XAMPP-ready ZIP package and resolved the leftover merge-conflict text in the setup script, tests, and previous README.

## Known limitations

- Recruiters cannot yet enter required/preferred job skills in the job editor; the career-insights service reads skills from `job_skills`, so skill matching is limited until that workflow is added.
- Resume upload/download, certification and project management, assessment workflows, a complete notification inbox, a migration runner, rate limiting, and full browser/database integration tests are not complete.
- Resume files live on local disk; there is no virus scanning, and recruiters see the resume only through the student-owned download route today. A recruiter-side resume viewer is not built yet.
- The admin CSV import covers accounts (students and recruiters). Placement drives, eligibility-based bulk shortlisting, announcement read receipts and editable `system_settings` are not built yet, and `jobs` still has no salary column, so CTC lives only on offers.
- The `offers` table arrives through the migration in `database/migrations/`; importing only `schema.sql` into an existing database will not add it.
- Do not use this project with real personal data until you have tested and reviewed the deployment configuration.

## Verify locally

From the project root, using PHP 8.2+:

```bat
C:\xampp\php\php.exe -l public\index.php
C:\xampp\php\php.exe -l public\recruiter.php
C:\xampp\php\php.exe -l public\admin.php
C:\xampp\php\php.exe -l public\career.php
C:\xampp\php\php.exe -l scripts\create-admin.php
C:\xampp\php\php.exe tests\run.php
```

The included automated suite covers pure service rules and static security/workflow checks. Complete `PHASE-7-TEST-CHECKLIST.md` on the target XAMPP/MySQL installation for live workflow acceptance; passing static tests alone does not verify a production deployment.

The shared capsule's gesture and spring logic has an optional Node check. It boots `liquid-glass.js` plus `recruiter-nav.js` in jsdom with stubbed geometry, so it verifies behaviour rather than pixels:

```bat
npm install --no-save --no-package-lock jsdom
node tests\js\liquid-glass.test.js
```

It covers 14 scenarios: boot geometry, click flight, one-tab width, drag and release, reversal/cancel, vertical gestures, synthetic-click suppression, GET filters and history, rapid switching, reduced motion, keyboard preview, cross-page handoff, option groups and mounting before layout.

Manual interaction check in a desktop browser and a phone-sized viewport: drag the dock slowly across all five tabs (the bubble should follow the pointer and stretch), reverse direction mid-drag (it should compress), release between tabs (it should settle on the nearest one), swipe the section content sideways on touch, use the GET filters on Jobs and Applicants, then use browser Back and Forward. Enable "reduce motion" in the OS to confirm transitions stop.

The admin rail and the student dock share the same engine and Node harness; `tests/run.php` additionally asserts the routed sections, shared material layers, spring constants, restore keys, glass groups, CSV-download exemptions and import limits.

Static checks cannot judge how the glass looks, how the spring feels or how touch scrolling behaves; review the three workspaces in a real browser at desktop, tablet and phone widths before accepting a visual change. The Node harness stubs layout, so it verifies geometry and gesture state, not pixels.
