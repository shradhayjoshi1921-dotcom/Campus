# Security notes

Implemented: password hashing/verification, session ID rotation after login, strict session mode, HttpOnly/SameSite cookie flags, CSRF verification for POST actions, PDO prepared statements, role allowlists during registration, escaped output, and no public admin signup. The repository-root `.htaccess` also blocks access to `.env` and internal folders when Apache overrides are enabled; a `public/` document root remains the recommended deployment.

Before production: enable HTTPS and `SESSION_SECURE=true`; set `APP_DEBUG=false`; use a least-privilege MySQL account; add rate limiting/account lockout, email verification, password reset, security headers/CSP, stronger session revocation, complete audit events, private resume-download authorization, and automated integration tests. Never expose this development instance to the public internet with default XAMPP credentials.

The first-admin setup command accepts a password argument. Avoid using a real password on shared machines where shell history or process listings may be visible.
