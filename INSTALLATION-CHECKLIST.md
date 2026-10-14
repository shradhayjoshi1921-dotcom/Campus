# Campus local verification checklist

Use this after merging the package into `C:\xampp\htdocs\Campus`.

1. Back up the existing project and database.
2. Confirm `.env` exists locally and is ignored by Git. Never place it in the ZIP or repository.
3. Start Apache and MySQL in XAMPP.
4. Confirm `.env` database values match the database imported from `database/schema.sql`.
5. From the project root, run `php -l` on `public/index.php`, `public/recruiter.php`, `public/admin.php`, and `scripts/create-admin.php`.
6. Run `php tests/run.php` and record the result.
7. Create the first admin only if one does not already exist.
8. Sign in as admin and test the admin portal with an empty database first.
9. Register a test recruiter. Approve the pending account and company in the admin portal. Sign out and verify the recruiter can sign in after approval.
10. As the approved recruiter, create a test job and publish it. As a student test account, confirm the job appears and submit one application. Verify duplicate submission is blocked.
11. Test interview scheduling and application status updates with test records only.
12. Before pushing, review `git status`, `git diff --cached`, and `.gitignore`. Do not commit `.env`, passwords, real resumes, uploads, or personal data.

## Important
A successful PHP syntax check and pure/static test suite do not prove that a configured MySQL database and browser workflows work on every machine. Complete steps 8–11 locally before describing the system as end-to-end verified.
