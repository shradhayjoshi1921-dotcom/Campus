# Architecture

- `public/` is the web document root; only front-controller and public assets belong here.
- `bootstrap/app.php` loads environment configuration and starts a hardened PHP session.
- `app/Core/Database.php` owns the PDO connection; native prepared statements and exception mode are enabled.
- `app/Core/Security.php` centralizes escaping, CSRF tokens, session identity, and basic authorization helpers.
- `app/Services/` contains framework-independent career eligibility, fit, gap, recommendation, and roadmap rules.
- `database/schema.sql` creates an empty normalized schema; it intentionally seeds no students, jobs, companies, applications, or placement statistics.
- Resume bytes belong in `storage/uploads/`, not under the public document root. A protected download controller still needs to be added before resume upload is enabled.

Career fit is an explainable rule-based estimate: skills 60%, academics 25%, role alignment 15%. It is not a trained ML model and is not a hiring probability. Hard eligibility is evaluated separately.
