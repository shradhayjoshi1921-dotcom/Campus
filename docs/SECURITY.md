# Security notes

Implemented in the foundation: password hashing/verification, session ID rotation after login, strict session mode, HttpOnly/SameSite cookie flags, CSRF verification for POST actions, PDO prepared statements, role allowlists during registration, escaped output, and no public admin signup.

Before production: enable HTTPS and `SESSION_SECURE=true`; add rate limiting and account lockout, email verification, password reset, security headers/CSP, audit events, per-route ownership checks, malware/content validation for uploads, private download authorization, and automated integration tests. Never expose this development instance to the public internet with default XAMPP credentials.
