# Integration and acceptance checklist

Use this checklist for live verification in XAMPP. The automated suite covers service rules and static route/security invariants; it does not replace database/browser acceptance.

## Install and bootstrap
- [ ] Back up any existing `Campus` folder and `ai_campus_portal` database.
- [ ] Start Apache and MySQL.
- [ ] Import `database/schema.sql` and configure `.env`.
- [ ] Confirm PHP 8.2+, PDO MySQL, `mbstring`, and JSON are enabled.
- [ ] Run PHP syntax checks and `php tests/run.php` from the project root.
- [ ] Create the first admin only if one does not already exist.
- [ ] Verify public registration offers only Student and Recruiter and refuses a second admin bootstrap.

## Student flow
- [ ] Register and sign in as a student; complete branch, CGPA, and graduation year.
- [ ] Add skills and verify they persist after reload.
- [ ] Confirm only published jobs from approved companies are visible.
- [ ] Apply to an eligible job; verify the application appears and duplicate application is blocked.
- [ ] Open Career insights and check fit, eligibility, skill gaps, roadmap, and the what-if simulation.
- [ ] Confirm the simulation does not alter saved skills.

## Recruiter and TPO/Admin flows
- [ ] Register a recruiter and confirm sign-in is blocked while pending.
- [ ] Approve the recruiter and company from the TPO/Admin workspace.
- [ ] Create and publish a test job; verify company ownership restrictions.
- [ ] Review an applicant, update status, and schedule a future interview.
- [ ] Verify students/recruiters receive 403 responses on routes outside their role.

## Responsive UI, security, and deployment
- [ ] Check the homepage, login/register, student workspace, recruiter workspace, and admin workspace on desktop and mobile widths.
- [ ] Verify keyboard operation of the mobile navigation and honor `prefers-reduced-motion`.
- [ ] Submit forms with missing/invalid CSRF tokens and verify no state changes.
- [ ] Verify `.env`, `app/`, `database/`, `scripts/`, and `storage/` are not accessible over HTTP.
- [ ] In production, set Apache document root to `public/`, require HTTPS, set `SESSION_SECURE=true`, set `APP_DEBUG=false`, and use least-privilege database credentials.

## Scope boundaries

- Career-fit scores are transparent heuristics, not trained AI and not probabilities of employment.
- Recruiter job-skill entry, secure resumes, certification/project CRUD, assessment UI, a full notification inbox, CSV exports, migrations, rate limiting, and complete automated browser tests remain outside the implemented scope.
