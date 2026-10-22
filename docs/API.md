# Routes and workflows

The current application uses server-rendered PHP forms. It does not expose a general-purpose JSON API.

| Page / workflow | Method | Authorization | Notes |
|---|---|---|---|
| Home, registration, sign-in | GET / POST via `public/index.php` | Public | Registration only allows student and recruiter roles; recruiters are pending approval by default. Forms include CSRF tokens. |
| Sign out | POST `public/index.php` | Authenticated session | CSRF-protected; clears the session. |
| Student profile and skills | POST `public/index.php?page=profile` | Student | Validated profile fields and skill updates. |
| Browse jobs and apply | POST `public/index.php?page=jobs` | Student | Only current published jobs from approved companies; academic requirements are checked on submission. |
| Application tracking | GET `public/index.php?page=applications` | Student | Shows the signed-in student's applications. |
| Career studio | GET / POST `public/career.php` | Student | Readiness, grouped recommendations, explainable fit per role, skill-gap priorities, career graph, saved six-week roadmap with task status, what-if simulation (writes nothing) and the career copilot. POST actions: `save_roadmap`, `task_status`, `toggle_target`, `ask_copilot` — all CSRF-protected and scoped to the signed-in student. |
| Resume review | GET / POST `public/resume-review.php` | Student | Extracts text from the student's own PDF, shows recognised fields, compares against one open role, rewrites one bullet, and applies only the fields the student ticks. POST actions: `analyse`, `approve`, `compare`, `improve`. |
| Practice | GET / POST `public/practice.php` | Student | Text mock interviews scored against a five-criterion rubric (`start_practice`, `answer`, `finish_practice`) and the published assessments (`start_assessment`, `submit_assessment`). |
| Recruiter AI tools | GET / POST `public/recruiter.php?section=ai` | Recruiter | Drafts a job description from pasted text, builds an interview kit from one of the recruiter's own jobs, and compares an applicant's stored resume with that job's requirements. Read-only: nothing is published, ranked or decided, and each resume read is audited as `resume.analysed`. |
| Institutional intelligence | GET `public/admin.php?section=intelligence` | TPO or admin | Read-only cohort report: readiness by branch, employer skill demand, training gaps, company coverage, application volume and records that need a human look. No student is named. |
| Recruiter workspace | GET / POST `public/recruiter.php` | Active recruiter | Company-owned job management, applicant status, and interview workflows. |
| TPO/Admin workspace | GET / POST `public/admin.php` | TPO or admin | Account/company decisions, moderation, overview/reporting, announcements, and audit activity. |

Every state-changing request should validate CSRF, authorization, and ownership. A future JSON API should use a consistent envelope such as `{"ok":true,"data":...}` or `{"ok":false,"error":{"code":"...","message":"..."}}`, with server-side validation on each route.
