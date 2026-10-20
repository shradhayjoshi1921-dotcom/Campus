# Campus — Placement & Career Intelligence

**Version 2.0.0** · PHP 8.2+ · MySQL/MariaDB · Apache/XAMPP

Campus is a server-rendered placement portal for students, recruiters, and placement teams. The interface uses an institute-focused navy-and-emerald design, responsive navigation, restrained frosted-glass accents, and reduced-motion-aware transitions. The homepage uses a CSS-built abstract hero rather than unverified campus photography. The website does not depend on remote font, Bootstrap, icon, or hero-image CDNs.

The recruiter workspace uses a responsive liquid-glass bottom dock with a smoothly sliding iOS-inspired bubble indicator, while keeping job and candidate information readable on compact screens. The database starts empty. No fake users, companies, jobs, applications, or placement statistics are seeded.

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
- Resume upload/download, certification and project management, assessment workflows, a complete notification inbox, CSV export, a migration runner, rate limiting, and full browser/database integration tests are not complete.
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
