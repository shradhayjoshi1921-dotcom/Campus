# Campus XAMPP setup checklist

1. Back up the current `Campus` folder and any existing `ai_campus_portal` database.
2. Extract the versioned ZIP so the project is at `C:\xampp\htdocs\Campus`.
3. Confirm `.env` exists locally and is ignored by Git. Never include it in the ZIP.
4. Start Apache and MySQL in XAMPP.
5. Import `database/schema.sql` through phpMyAdmin; confirm `.env` points to the same database.
6. Confirm PHP 8.2+, PDO MySQL, `mbstring`, and JSON are enabled.
7. Run the `php -l` checks and `php tests/run.php` listed in `README.md`.
8. Create the first admin only when one does not already exist.
9. Sign in as admin and check the portal using an empty database first.
10. Register a test recruiter, approve the account/company, then publish a test job.
11. Register a test student, complete the profile, browse the job, and test application submission and duplicate prevention.
12. Test interview scheduling, application-status updates, CSRF rejection, and role restrictions.
13. Review `git status`, `.gitignore`, and the final ZIP contents. Exclude `.env`, credentials, uploads, logs, and personal data.

The source-only test suite is not a substitute for live MySQL and browser testing. Use test records only until deployment security has been reviewed.
