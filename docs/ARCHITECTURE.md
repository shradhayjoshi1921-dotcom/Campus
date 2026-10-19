# Architecture

- `public/` is the recommended Apache document root; only public PHP entry points and static assets belong there.
- `public/assets/` contains local CSS, JavaScript, and the generated campus hero image. The UI does not need an external asset CDN.
- `bootstrap/app.php` loads environment configuration, includes core/services, and starts a hardened PHP session.
- `app/Core/Database.php` owns the PDO connection; exception mode, UTF-8, and native prepared statements are enabled.
- `app/Core/Security.php` centralizes HTML escaping, CSRF tokens, session identity, and basic role checks.
- `app/Services/` contains framework-independent career eligibility, fit, gap, recommendation, and roadmap rules.
- `database/schema.sql` creates an empty normalized schema; it intentionally seeds no students, jobs, companies, applications, or placement statistics.
- Resume bytes belong in private `storage/uploads/`, not under the public document root. A protected upload/download workflow still needs to be implemented before accepting resumes.

Career fit is an explainable rule-based estimate: required skills 60%, academics 25%, role alignment 15%. It is not a trained ML model and is not a hiring probability. Hard eligibility is evaluated separately.

The schema currently uses `CREATE TABLE IF NOT EXISTS`; it does not migrate existing table definitions. Back up data before changing schema and use a migration runner for future evolution.
