# API and workflow notes

The current foundation exposes server-rendered form workflows through `public/index.php`.

| Action | Method | Authorization | Validation |
|---|---|---|---|
| Register | POST `?page=register` | Public | CSRF, email format, name, role allowlist, password length |
| Sign in | POST `?page=login` | Public | CSRF, password verification, active account |
| Sign out | POST | Authenticated session | CSRF; destroys session |

JSON API routes and the remaining student/recruiter/TPO/admin workflows are not implemented yet. They should use a consistent response envelope (`{"ok":true,"data":...}` or `{"ok":false,"error":{"code":"...","message":"..."}}`), validate request data on the server, and enforce role plus record ownership on every request.
