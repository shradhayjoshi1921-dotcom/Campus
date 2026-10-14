# Campus — AI Placement & Career Intelligence Portal

PHP 8.2+, MySQL/MariaDB, Apache, PDO, Bootstrap 5 and vanilla JavaScript. This package combines the currently implemented student placement workflow, recruiter workspace, and TPO/admin portal in one project directory. The database starts empty; no fake users, jobs, applications, or placement metrics are seeded.

## Requirements
- XAMPP with PHP 8.2+ and MySQL/MariaDB
- PHP PDO MySQL extension enabled
- Git (optional, for version control)

## Install in Windows/XAMPP
1. Back up any existing `C:\xampp\htdocs\Campus` folder before merging these files. Extract this package as `C:\xampp\htdocs\Campus` or merge its contents into your existing project carefully.
2. Start Apache and MySQL in XAMPP Control Panel.
3. Open `http://localhost/phpmyadmin/`. Back up any existing Campus database first. Import `database/schema.sql` into the configured database. The schema creates `ai_campus_portal` and uses `CREATE TABLE IF NOT EXISTS`; that does not migrate existing columns. Review `database/schema.sql` before applying it to a database with existing data.
4. Copy `.env.example` to `.env` and set `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `APP_URL`, and a unique random `APP_KEY`. Do not commit `.env`.
5. Open `http://localhost/Campus/public/`.
6. If no admin exists, create the first one from the project root using `php scripts/create-admin.php "Portal Admin" admin@example.com "Use-A-Unique-Strong-Password"`. This is a local CLI setup step. Do not share or commit the password. If PHP isn't on PATH, run `C:\xampp\php\php.exe scripts\create-admin.php ...` from Command Prompt.
7. Sign in at `http://localhost/Campus/public/index.php?page=login`. Admin/TPO routes require an active account with the corresponding role.

Recommended production setup: configure Apache's document root to the project's `public/` directory. Do not expose `app/`, `storage/`, `.env`, or `scripts/` publicly.

## Main routes
- Main portal: `http://localhost/Campus/public/`
- Student jobs: `http://localhost/Campus/public/index.php?page=jobs` (student sign-in required)
- Student applications: `http://localhost/Campus/public/index.php?page=applications` (student sign-in required)
- Recruiter workspace: `http://localhost/Campus/public/recruiter.php` (active recruiter sign-in required)
- TPO/Admin workspace: `http://localhost/Campus/public/admin.php` (active TPO/admin sign-in required)

## Current implemented scope
- Phase 1 foundation: environment loader, PDO connection, session/security helpers, database schema, responsive dark landing page, student/recruiter registration and login/logout, CLI first-admin bootstrap.
- Career rule services: eligibility, fit score, skill gaps, recommendation filtering and learning-roadmap generation at service level.
- Student profile and skills: profile editing, academic details and skill updates with validation, CSRF checks, role checks, prepared statements and transactions.
- Student placement flow: browse published, non-expired jobs from approved companies; academic eligibility checks; application submission; duplicate prevention; application status listing and submission history.
- Career Intelligence page (`public/career.php`): student-only fit estimates, required/preferred skill gaps, learning roadmap suggestions and a what-if skill simulation. Simulations do not modify saved profile data.
- Recruiter workspace: company profile submission, company-owned job create/edit/status operations, applicant status updates, interview scheduling, and audit entries. Publishing requires company approval.
- TPO/Admin workspace: pending account review, company approval/rejection, job moderation, student/application overview, department-wise selection report, announcements/notifications and recent audit activity. TPO/admin actions use role checks, CSRF validation, prepared statements, transactions and audit records.

## Known limitations — not yet complete
- Certification/project CRUD and secure resume upload/download.
- Complete end-to-end browser tests and live MySQL integration verification in every supported XAMPP setup.
- Dedicated TPO account creation UI, CSV exports, a migration runner, full JSON API, rate limiting, assessment workflows, and a complete notification center.
- Career insights are now available as a student page, but fit scores are rule-based heuristics, not trained AI or probabilities of hiring. Recommendations are based on the skills and academic fields currently represented in the schema.
- Cinematic imagery may rely on remotely hosted assets and network access.

Do not treat these limitations as implemented. Do not enter real student or company personal data until you've tested and reviewed the deployment.

## Phase 7 acceptance testing

Use [`PHASE-7-TEST-CHECKLIST.md`](PHASE-7-TEST-CHECKLIST.md) to verify the integrated workflows on your own XAMPP/MySQL setup. This package has not been live-tested against your local database.

## Tests
From the project root run:

```bash
php -l public/index.php
php -l public/recruiter.php
php -l public/admin.php
php -l public/career.php
php -l scripts/create-admin.php
php tests/run.php
```

The included test suite checks career rules and static security/route invariants. It is not a substitute for live MySQL integration or browser testing.

## GitHub
Repository: https://github.com/shradhayjoshi1921-dotcom/Campus

Before committing, review `git status` and `git diff --cached`, and ensure `.env`, credentials, private resumes, uploaded documents and logs are excluded.
