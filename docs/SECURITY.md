# Security notes

Implemented: password hashing/verification, session ID rotation after login, strict session mode, HttpOnly/SameSite cookie flags, CSRF verification for POST actions, PDO prepared statements, role allowlists during registration, escaped output, and no public admin signup. The repository-root `.htaccess` also blocks access to `.env` and internal folders when Apache overrides are enabled; a `public/` document root remains the recommended deployment.

Resume downloads are authorized per request: the owner, T&P/admin staff, or a recruiter whose company received an application from that student. Recruiter views are written to `audit_logs` as `resume.viewed` with a hashed IP.

Before production: enable HTTPS and `SESSION_SECURE=true`; set `APP_DEBUG=false`; use a least-privilege MySQL account; add rate limiting/account lockout, email verification, password reset, security headers/CSP, stronger session revocation, complete audit events (sign-in and sign-out are still not recorded), and automated integration tests. Never expose this development instance to the public internet with default XAMPP credentials.

The first-admin setup command accepts a password argument. Avoid using a real password on shared machines where shell history or process listings may be visible.

## Career intelligence, resumes and the optional AI layer

- **Student data stays student-scoped.** Every statement in `app/Data/StudentData.php` and `app/Data/CareerStore.php` filters on the signed-in student's id, and roadmap task updates re-check ownership in the `WHERE` clause rather than trusting the form.
- **Resume text is processed locally.** `ResumeTextService` reads the PDF from `storage/uploads/resumes/`, never from a request path, and refuses encrypted files, files over 8 MB and image-only PDFs with a stated reason. Extracted text is stored in `resume_analyses` for the owner only; nothing is written to the profile until the student ticks the fields, and an approved skill is stored as a *claim* with a resume evidence row.
- **Recruiter access to a resume is unchanged and audited.** The AI comparison tool only sees applications to that recruiter's own company, and every read appends `resume.analysed` to `audit_logs` with a hashed IP.
- **The AI provider is optional and off by default.** `AI_ENABLED=false` means the copilot, resume wording and job-description draft all run on deterministic rules. When enabled, the key is read server-side from `.env` through `Env::get()`, never rendered into HTML or JavaScript, payloads are capped at 12 000 characters, hourly quotas apply per feature, and `ai_usage_logs` records feature, sizes, latency and outcome — never prompt or answer text.
- **No invasive inference.** The mock interview is text-only: there is no camera, microphone, keystroke, emotion, personality or accent analysis anywhere in the codebase, and the page says so.
- **No automated decisions about people.** Fit scores never override eligibility, the recruiter tools never rank or reject a candidate, and the TPO report names no student and labels every flag a review prompt rather than a finding.
- **Migrations are additive.** `scripts/migrate.php` only creates tables or adds nullable columns, tolerates duplicates, runs one transaction per file and records applied files in `schema_migrations`. Nothing is dropped or rewritten.
