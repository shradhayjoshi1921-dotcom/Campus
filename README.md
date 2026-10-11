# AI Campus Placement & Career Intelligence Portal

A PHP 8.2+, MySQL/MariaDB, Apache, PDO, Bootstrap 5 and vanilla JavaScript application foundation for Windows/XAMPP. The interface uses a premium jet-black visual system and a campus/building/classroom cinematic introduction. The database starts empty; no fabricated placement metrics are included.

## Requirements
- XAMPP with PHP 8.2+ and MySQL/MariaDB
- PDO MySQL extension enabled
- Optional Composer for autoloading later modules

## Install on Windows/XAMPP
1. Extract this folder to `C:\xampp\htdocs\ai-campus-placement-portal`.
2. Start Apache and MySQL in XAMPP Control Panel.
3. Open `http://localhost/phpmyadmin`, import `database/schema.sql` (it creates `ai_campus_portal`). The schema uses `CREATE TABLE IF NOT EXISTS` and does not seed sample data. Back up any existing database before schema changes.
4. Copy `.env.example` to `.env` and set DB host, database, username, password, APP_URL, and a random APP_KEY. Local XAMPP commonly uses `root` with an empty password; change this for any non-disposable environment.
5. Open `http://localhost/ai-campus-placement-portal/public/`.
6. Create the first administrator from a terminal in the project directory: `php scripts/create-admin.php "Portal Admin" admin@example.com "A-Long-Unique-Password-Here"`. This command only works while no admin exists. Do not put the password in shell history on a shared machine.

Recommended Apache VirtualHost document root is the project's `public/` directory. If using the simple URL above, the project is under htdocs but only `public/` is intended as the web entry point; do not expose `app/`, `storage/`, `.env`, or scripts.

## Tests
Run `php tests/run.php` from the project directory. This test suite covers the pure PHP career rules and basic static security invariants. Database integration tests require a configured MySQL server and are not claimed by the pure test suite.

## Current implementation status
- [x] Phase 1 foundation: environment loader, PDO, session/security helpers, schema, dark responsive landing page, intro controls, student/recruiter registration, login/logout, one-time CLI admin bootstrap.
- [x] Initial career rule services: eligibility, fit score, skill gaps, recommendation filtering, roadmap generation (unit-level only).
- [ ] Full cinematic camera assets are remote licensed/hosted image URLs and require network access; this is layered image parallax, not navigable 3D.
- [ ] Full student profile editing, resume upload/download, job CRUD and application workflows.
- [ ] Recruiter approval, ownership enforcement and interview workflows.
- [ ] TPO and admin management/reporting interfaces.
- [ ] Assessment authoring/taking and full notification center.
- [ ] JSON API routes, migration runner, rate limiting, full audit trail, end-to-end browser testing.

Do not treat unchecked items as implemented. See `docs/SECURITY.md`, `docs/ARCHITECTURE.md`, and `docs/API.md`.

## Latest implementation update — Student profile slice

Implemented in this build:
- Student-only profile page (`index.php?page=profile`) linked from the signed-in navigation.
- Profile editing for name, phone, degree, branch, semester, CGPA, graduation year, and bio.
- Student skill editing using a comma-separated skill list, stored through the `skills` and `student_skills` tables.
- Server-side validation for lengths, CGPA (0–10), semester, and graduation year.
- CSRF validation on profile/skill writes, role checks, prepared SQL statements, and escaped form output.
- Profile/skill updates run in database transactions; profile changes do not create sample records.

Tests executed locally with PHP CLI: `php tests/run.php` — **10 passed, 0 failed**. `php -l public/index.php` and `php -l tests/run.php` also passed. These are PHP syntax and pure/static rule tests; no live MySQL or browser end-to-end test was run in this environment.

Still not implemented in this slice: resume upload/download, certification/project CRUD, job CRUD and applications, recruiter approval and interview workflow, TPO/admin reporting, assessments/notifications, and browser end-to-end tests.
