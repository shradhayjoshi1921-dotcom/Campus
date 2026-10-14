# Phase 7 — Integration and Acceptance Checklist

This checklist is for local acceptance testing in XAMPP. Automated tests currently cover service rules and static route/security invariants; a live database/browser test is still required on the target machine.

## Install and bootstrap
- [ ] Back up the current `Campus` folder and any existing `ai_campus_portal` database.
- [ ] Start Apache and MySQL in XAMPP.
- [ ] Import `database/schema.sql` into the database named in `.env`.
- [ ] Copy `.env.example` to `.env`; set DB values and a unique `APP_KEY`.
- [ ] Run `C:\xampp\php\php.exe -l public\index.php`, and repeat for `career.php`, `recruiter.php`, `admin.php`, and `scripts\create-admin.php`.
- [ ] Run `C:\xampp\php\php.exe tests\run.php` from the project root.
- [ ] Create the first admin with `C:\xampp\php\php.exe scripts\create-admin.php "Portal Admin" admin@example.com "Use-A-Unique-Password-14+"`.
- [ ] Verify a second admin bootstrap attempt is refused and public registration offers only Student and Recruiter.

## Student workflow
- [ ] Register a student, sign in, and complete profile (branch, CGPA, graduation year).
- [ ] Add comma-separated skills and verify they persist after reload.
- [ ] Open Jobs; verify only published jobs from approved companies are visible.
- [ ] Apply to an eligible job; verify it appears in Applications and cannot be applied to twice.
- [ ] Open `public/career.php` via the Career insights navigation; check fit score, missing skills, roadmap and what-if skill simulation.
- [ ] Confirm the what-if simulation does not alter saved profile skills.

## Recruiter workflow
- [ ] Register a recruiter; verify login is blocked while pending.
- [ ] Approve the recruiter account and approve its company through Admin/TPO.
- [ ] Sign in to Recruiter workspace; create a job as draft, then publish it.
- [ ] Verify a recruiter cannot edit another company's job or publish before company approval.
- [ ] Review an applicant, update status, and schedule a future interview for a shortlisted applicant.

## Admin/TPO workflow
- [ ] Sign in as admin and open `public/admin.php`.
- [ ] Approve/reject pending users and companies.
- [ ] Publish/pause/close jobs; verify company approval restrictions.
- [ ] Publish an announcement; review department report and audit log.
- [ ] Verify Student/Recruiter accounts receive 403 on Admin/TPO route and Student-only routes.

## Security and deployment
- [ ] Submit a form with a missing/invalid CSRF token and verify state is not changed.
- [ ] Test invalid email/password, duplicate email, invalid profile ranges and malformed job fields.
- [ ] Review uploads and personal data handling before adding resume upload/download; that workflow is not implemented in this build.
- [ ] In production, set Apache document root to `public/`; do not expose `.env`, `app/`, `storage/`, `database/`, or `scripts/`.
- [ ] Disable verbose errors, use least-privilege database credentials, configure HTTPS, and take database backups.

## Known scope boundaries
- Fit and recommendation scores are explainable rule-based heuristics, not trained AI and not probabilities of employment.
- This build does not yet include resume upload/download, certification/project CRUD, assessment-taking UI, CSV exports, a migration runner, rate limiting, or complete automated browser tests.
- Live MySQL integration and browser acceptance cannot be certified by the source-only test suite; complete the checklist on your XAMPP installation.
