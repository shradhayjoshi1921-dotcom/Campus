# Routes and workflows

The current application uses server-rendered PHP forms. It does not expose a general-purpose JSON API.

| Page / workflow | Method | Authorization | Notes |
|---|---|---|---|
| Home, registration, sign-in | GET / POST via `public/index.php` | Public | Registration only allows student and recruiter roles; recruiters are pending approval by default. Forms include CSRF tokens. |
| Sign out | POST `public/index.php` | Authenticated session | CSRF-protected; clears the session. |
| Student profile and skills | POST `public/index.php?page=profile` | Student | Validated profile fields and skill updates. |
| Browse jobs and apply | POST `public/index.php?page=jobs` | Student | Only current published jobs from approved companies; academic requirements are checked on submission. |
| Application tracking | GET `public/index.php?page=applications` | Student | Shows the signed-in student's applications. |
| Career insights | GET `public/career.php` | Student | Uses saved profile/skills to calculate rule-based fit, eligibility, skill gaps, and roadmap suggestions. |
| Recruiter workspace | GET / POST `public/recruiter.php` | Active recruiter | Company-owned job management, applicant status, and interview workflows. |
| TPO/Admin workspace | GET / POST `public/admin.php` | TPO or admin | Account/company decisions, moderation, overview/reporting, announcements, and audit activity. |

Every state-changing request should validate CSRF, authorization, and ownership. A future JSON API should use a consistent envelope such as `{"ok":true,"data":...}` or `{"ok":false,"error":{"code":"...","message":"..."}}`, with server-side validation on each route.
