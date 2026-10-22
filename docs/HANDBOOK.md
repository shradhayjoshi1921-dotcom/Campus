# Campus Placement Portal
**User handbook: install, run and use every workspace**

Version 2.0.0 - 22 October 2026
Repository: github.com/shradhayjoshi1921-dotcom/Campus
Stack: PHP 8.0+ (8.2 recommended), MySQL/MariaDB, Apache (XAMPP)

This handbook is for the person setting the portal up and for the people who
will use it: students, recruiters and the Training & Placement Office. It has
the exact click path for every screen, a complete pack of example data you can
type in, and a troubleshooting table for the errors this project actually
throws.

## What this project is

Campus is a placement portal for Swami Vivekanand Institute of Engineering and
Technology. One PHP application, one MySQL database, four kinds of account:

- **Public site** - landing page, sign in, create account.
- **Student workspace** - profile, skills, resume, opportunities, applications,
  notifications, career studio, resume review, practice.
- **Recruiter workspace** - company profile, job publishing, applicant
  pipeline, interviews, AI tools.
- **TPO / Admin workspace** - approvals, students, placements and offers, job
  moderation, reports, announcements, governance, institutional intelligence.

Three ideas hold the product together, and every screen is built around them:

1. **Eligibility is a fact; fit is an estimate.** CGPA, branch and graduation
   year decide whether a student "can" apply. The fit percentage is a rule-based
   alignment score that never overrides eligibility.
2. **A listed skill is a claim, not proof.** A project, a certification, an
   assessment result or staff verification raises a skill's evidence level.
3. **Every number can be interrogated.** Scores show their components, weights
   and missing skills. Where there is no data, the UI shows "Nothing to measure
   yet" instead of a zero.

### What it is not

- Not trained machine learning, and not a probability of being hired.
- No placement guarantee, no ranking of students as "placeable".
- No webcam, microphone, face or emotion analysis anywhere.
- No external AI provider required. `AI_ENABLED=false` is the default and every
  AI-flavoured feature falls back to deterministic rules and says so.

## Before you start

### Requirements

| Item | Needed | Notes |
|---|---|---|
| PHP | 8.0 or newer, 8.2 recommended | Constructor promotion, `match` and `str_contains` are 8.0 features. 8.1-only syntax was removed. |
| MySQL / MariaDB | MySQL 5.7+, MariaDB 10.3+ | InnoDB, utf8mb4. XAMPP's default MySQL is fine. |
| Apache | with PHP module | XAMPP Control Panel: start Apache and MySQL. |
| PHP extensions | `pdo_mysql`, `mbstring`, `json` | `zlib` for PDF text extraction, `curl`/`openssl` only if you enable an AI provider. |
| Browser | any current one | No build step, no Composer, no npm required to run. |

### The PHP version trap (read this first)

If the page shows a raw PHP error such as

```text
Parse error: syntax error, unexpected identifier "ScoringModel",
expecting variable in ...\app\Services\EvidenceService.php on line 16
```

or a page that says **PHP upgrade required**, then Apache is running a PHP build
older than 8.0. That is a server setting, not a browser problem.

Check which PHP the command line uses, and which one Apache uses:

```bat
C:\xampp\php\php.exe -v
```

`scripts\doctor.php` prints the version it sees plus `PHP_BINARY`, so you can
compare. To move XAMPP to a newer PHP, install a current XAMPP (8.2) into a
separate folder or point Apache at a newer `php` folder, then restart Apache
from the XAMPP Control Panel. Keep the CLI and Apache versions the same.

### Accounts and roles

| Role | Created by | Starts as | Can do |
|---|---|---|---|
| `student` | self-registration | active | Profile, skills, resume, applications, career studio, resume review, practice. |
| `recruiter` | self-registration | pending | Company profile, jobs, applicants, interviews, AI tools - after approval. |
| `tpo` | CLI or admin import | active | Every admin section except destructive user management. |
| `admin` | `scripts/create-admin.php` | active | Everything, including account decisions, imports and settings. |

## Install and run

### Step 1 - put the project in the web root

```text
C:\xampp\htdocs\Campus
```

The document root for the URL used in this handbook is `Campus\public`. If you
prefer `http://localhost/` with no `/Campus/public`, set Apache's document root
to `C:\xampp\htdocs\Campus\public`.

### Step 2 - start the services

Open the XAMPP Control Panel and press **Start** next to Apache and MySQL.

### Step 3 - create the database and tables

The schema file creates the database itself, so you do not need to create one
by hand. In phpMyAdmin: **Import** -> choose `database\schema.sql` -> **Go**.
That creates `ai_campus_portal` with 29 tables.

### Step 4 - create `.env`

Copy `.env.example` to `.env` and check these values. The defaults already match
a stock XAMPP install (user `root`, empty password).

```dotenv
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost/Campus/public
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=ai_campus_portal
DB_USER=root
DB_PASS=
DB_TIMEZONE=+05:30
APP_TIMEZONE=Asia/Kolkata
SESSION_SECURE=false
AI_ENABLED=false
```

`APP_DEBUG=true` is for local work only. Set it to `false` before letting anyone
else use the portal.

### Step 5 - run the environment check

```bat
cd C:\xampp\htdocs\Campus
C:\xampp\php\php.exe scripts\doctor.php
```

It prints one line per check: PHP version, extensions, `.env`, DB host/name/user
(the password is never printed), the connection, which `schema.sql` tables are
missing, pending migrations and whether an admin account exists. Fix every
`[FAIL]` line before continuing. `--no-db` skips the database section.

### Step 6 - apply the migrations

Four additive migration files live in `database\migrations`. They only create
new tables and add nullable columns; nothing is dropped or rewritten.

```bat
C:\xampp\php\php.exe scripts\migrate.php --status
C:\xampp\php\php.exe scripts\migrate.php
```

Safe to re-run: a file is recorded in `schema_migrations` only after its last
statement succeeds, and an "already exists" column is skipped.

### Step 7 - create the first administrator

```bat
C:\xampp\php\php.exe scripts\create-admin.php "Portal Admin" admin@sviet.test "Change-This-Pass-2026"
```

The password must be at least 14 characters. The command refuses to run if an
admin already exists, so you cannot accidentally create a second one.

### Step 8 - open the portal

```text
http://localhost/Campus/public/
```

### Every URL you will use

| Surface | URL |
|---|---|
| Portal home / sign in | `/Campus/public/` and `/Campus/public/index.php?page=login` |
| Student: dashboard | `/Campus/public/index.php?page=dashboard` |
| Student: profile, skills, resume | `/Campus/public/index.php?page=profile` |
| Student: opportunities | `/Campus/public/index.php?page=jobs` |
| Student: applications | `/Campus/public/index.php?page=applications` |
| Student: career studio | `/Campus/public/career.php` |
| Student: resume review | `/Campus/public/resume-review.php` |
| Student: practice | `/Campus/public/practice.php` |
| Recruiter workspace | `/Campus/public/recruiter.php` |
| Recruiter: AI tools | `/Campus/public/recruiter.php?section=ai` |
| TPO / Admin workspace | `/Campus/public/admin.php` |
| TPO / Admin: intelligence | `/Campus/public/admin.php?section=intelligence` |

## The first twenty minutes

Work in this order; each step depends on the one before it.

1. **Admin signs in.** Open `/public/admin.php` with the account from Step 7.
2. **Approve the demo recruiter account and company.** Admin -> "Approvals".
   Recruiter accounts are created `pending`, and a recruiter cannot publish a
   job until their company profile is approved.
3. **Recruiter completes the company profile and publishes a job.**
   Recruiter -> "Company", then "Jobs". Use job J1 from section 9.
4. **Student registers, fills the profile and adds skills.** Use S1 from
   section 9; copy the branch string exactly.
5. **Student opens the career studio.** The published job appears with a fit
   percentage, a band, matched and missing skills, and an eligibility verdict.
6. **Student applies.** Recruiter moves the application to "Shortlisted" and
   schedules an interview.
7. **TPO records the offer** in "Placements". The student sees it on the
   dashboard and on the applications page.

## Student workspace

Sign in at `/public/index.php?page=login`. The bottom dock has five
destinations - Home, Opportunities, Applications, Alerts, Profile - and the
bubble menu at the top adds Career insights, Resume review and Interview
practice.

### 1. Profile - the data every score depends on

`index.php?page=profile`. Fields:

| Field | Limit | Why it matters |
|---|---|---|
| Full name | 120 characters | Shown to recruiters on your applications. |
| Phone | 30 characters | Kept in the portal only. |
| Degree | 120 characters, e.g. `B.Tech Computer Science` | Reported to the TPO. |
| Branch | 120 characters, e.g. `Computer Science and Engineering` | Eligibility compares this string **exactly** (ignoring case). Use one spelling everywhere. |
| Semester | 1 to 20 | Profile completeness. |
| CGPA | 0 to 10, two decimals | Hard eligibility and the academic component of fit. |
| Graduation year | 2000 to 2150 | Matched against a job's graduation year when the job sets one. |
| Bio | 3,000 characters | Shown on your applications. |

**Skills** are a plain comma-separated list (`PHP, MySQL, Git`). The portal
never trusts this list as proof: a listed skill counts as "claimed" until a
project, a certification, an assessment result or a TPO verification backs it.

**Resume** must be a PDF of 5 MB or less. Files that are not really PDFs are
rejected. You can delete and re-upload at any time.

### 2. Opportunities

`index.php?page=jobs` lists published roles from approved companies. Each card
shows location, employment type, the minimum CGPA, branch and year restrictions,
the required and preferred skills, and whether "you" meet the hard criteria.
Applying takes an optional cover note of up to 2,000 characters; a duplicate
application to the same job is impossible (one per student per job).

### 3. Applications and notifications

`index.php?page=applications` shows status, applied date and the interview or
offer you are the subject of. Status changes, announcements and interview calls
arrive as notifications in "Alerts".

### 4. Career studio - `career.php`

| Panel | What it shows |
|---|---|
| Readiness scorecard | Six categories (profile, resume, skills, projects, assessments, interview). A category with no data shows "Nothing to measure yet", never 0%. |
| Recommendations | Published roles grouped into best fit, eligible, prepare and stretch. Eligibility is decided before fit. |
| Fit explanation | Component table: skills, evidence, academic, role - with the weight used and the reason for each. |
| Target role | Choose a role; the gap list separates "missing" from "claimed", and the career graph marks each skill demonstrated, claimed or missing. |
| Roadmap | Generates a week-by-week plan from the real gaps with task types, hour estimates and one resource per skill. Tick a task as in progress or completed; it is saved. |
| What-if simulator | Add hypothetical skills, raise CGPA, or mark a skill as evidenced. It writes nothing and says "simulated". |
| Copilot | Ask "where do my applications stand?" and it answers from your own rows. With `AI_ENABLED=false` it answers from rules and labels the answer as such. |

### 5. Resume review - `resume-review.php`

1. Press **Read the PDF**. Text-based PDFs are parsed locally; scanned,
   encrypted or oversized files are refused "with a reason".
2. Check the recognised fields (name, email, phone, links, sections). Nothing is
   written until you approve it.
3. Tick the skills you actually have, then **Approve**. They are added as
   claims, not as verified skills.
4. **Match against a role** - pick one open job and see matched and missing
   skills with the quoted line from your resume.
5. **Rewrite one bullet** - the tool rewrites wording; it never invents a metric
   or a number.

### 6. Practice - `practice.php`

Two tabs:

- **Mock interview.** Choose a role (or "General practice"), answer four
  questions in your own words (up to 4,000 characters each). Each answer is
  scored against a five-criterion rubric and you get notes plus a stronger shape
  for the same answer. Text only - no camera, microphone or emotion analysis.
- **Assessments.** Shows the assessments the placement cell has published. A
  submission under five questions is labelled "Not enough evidence" rather than
  scored as weak. A score of 60% or more marks the tagged skill as demonstrated.

## Recruiter workspace

`/public/recruiter.php`. Your account starts `pending`: you can build the company
profile and draft jobs, but publishing is locked until the TPO approves both.

### 1. Company profile - "Company"

| Field | Notes |
|---|---|
| Company name (required) | 180 characters. Must match the legal name students will recognise. |
| Website | `https://` URL. |
| Industry | e.g. `Software product`, `Analytics`, `Consulting`. |
| Your designation | e.g. `Talent Acquisition Lead`. |
| Company description | 4,000 characters. Do not put confidential information here - students read it. |

Press **Submit for review**. While the profile is pending, job publishing stays
locked and the button says so.

### 2. Jobs - "Jobs"

| Field | Notes |
|---|---|
| Job title (required) | 180 characters. |
| Location | City, or `Remote`. |
| Employment type | `Full-time`, `Internship`, `Part-time`. |
| Minimum CGPA (required) | 0 to 10. This is a hard filter, not a preference. |
| Eligible branch | Leave blank for all branches. Otherwise the **exact** branch string, e.g. `Computer Science and Engineering`. |
| Graduation year | e.g. `2027`. Leave blank to accept any year. |
| Application deadline (IST) | After this moment the role stops accepting applications. |
| Role description (required) | 12,000 characters. |
| Required skills | Comma separated, e.g. `PHP, SQL, Git`. These drive fit, gap and roadmap for every student. |
| Preferred skills | Nice-to-have list. A skill already listed as required is not duplicated. |
| Listing status | `Published`, `Draft`, `Paused`, `Closed`. |

Click **Save role**. Reuse the spellings the page suggests ("Already used on the
portal") so student skills match your requirements instead of creating near
duplicates like `Node` and `Node.js`.

### 3. Applicants

Each application can be moved through `submitted` -> `under_review` ->
`shortlisted` -> `interview` -> `selected` or `rejected`. Every change is
written to the status history and notifies the student. A recruiter can open the
PDF resume of a candidate who applied to their own company's job; each view is
audited.

### 4. Interviews - "Interviews"

Interviews are scheduled against a **shortlisted** application. Fields: date and
time (IST), format (`Online`, `On-site`, `Phone`), and a meeting link or venue.
You can reschedule or cancel; the student is notified each time.

### 5. AI tools - `recruiter.php?section=ai`

Three read-only tools. None of them can publish anything.

| Tool | What it does |
|---|---|
| Draft a job description | Paste prose; you get structured fields (title, location, type, minimum CGPA, branch, year, deadline, responsibilities, required and preferred skills) plus a list of the fields it could not infer. Copy what you want into the job form. |
| Interview kit | Pick one of your jobs; you get question sets per required skill with a rubric per question. |
| Compare an applicant | Pick a job and an applicant; you get matched and missing skills between their stored resume and your requirements, with quoted lines. The read is audited as `resume.analysed`. |

## TPO and Admin workspace

`/public/admin.php`. Sections: Overview, Approvals, Students, Placements, Jobs,
Reports, Comms, Governance, Intelligence.

### Approvals

- **Recruiter accounts** (`pending` -> active/inactive) with a note.
- **Company profiles** (`pending` -> approved/rejected). Only approved companies
  can publish jobs.
- **Jobs** submitted for moderation.

Every decision takes an optional note, is written to the audit log, and notifies
the affected recruiter.

### Students

Search the student list, review profile completeness, and nudge students whose
profiles are too empty to be measured. **Bulk CSV import** accepts this header
row (aliases such as `account type` for `role` are understood):

```text
name,email,role,branch,cgpa,graduation_year,company,designation
Aarav Sharma,aarav.sharma@sviet.test,student,Computer Science and Engineering,8.40,2027,,
Priya Menon,priya.menon@northwind.test,recruiter,,,,"Northwind Software","Talent Acquisition Lead"
```

The import is preview-first: it validates every row, shows the errors, and only
writes when you press **Commit**.

### Placements and offers

Create an offer against an application: CTC in LPA, offer date, and status
(`offered`, `accepted`, `declined`, `joined`, `revoked`). Add a joining date when
a candidate joins. The student sees the CTC and status on their dashboard.

### Reports and Comms

- **Reports** summarises applications, offers and company coverage, with CSV
  export.
- **Comms** publishes an announcement to `all`, `students`, `recruiters` or
  `tpo`. Publishing also queues one notification per recipient - that is how
  students actually see it.

### Governance

The audit log: every privileged action with actor, action, entity, IP hash and
metadata. Filter by action and export.

### Intelligence - `admin.php?section=intelligence`

| Panel | Reads |
|---|---|
| Readiness by branch | Students, measured students and average readiness per branch. A branch with no measured student shows a dash, never 0%. |
| Skill demand | Required and preferred skills across published jobs, plus skill pairs that appear together. |
| Training gaps | Skills the cohort lacks, ranked by how many students are affected. |
| Company matching | Which branches a company's roles actually fit. |
| Trend | Application volume by month. Under six months of data it says "insufficient" instead of drawing a line. |
| Review prompts | Records worth a human look (stale applications, missing profile data). Each one is labelled a review prompt, not a finding. |

No student is named anywhere in this section.

## How the numbers work

### Fit score

Four components, each 0-100, combined with fixed weights:

| Component | Weight | What it measures |
|---|---|---|
| Skills | 0.45 | Share of the role's **required** skills the student lists. |
| Evidence | 0.20 | How strongly those skills are backed by projects, certifications, assessments or verification. |
| Academic | 0.20 | `min(100, CGPA / minimum CGPA x 100)`. A 9.1 CGPA against a 7.0 minimum scores 100, not 130. |
| Role | 0.15 | Reserved for target-role alignment; 100 by default. |

A component with no data is `null` and its weight is redistributed across the
rest, so a missing input lowers confidence instead of inventing a zero. When a
job lists **no** required skills at all, the formula falls back to academic 0.55
and role 0.45 and says so.

### Bands

| Score | Band |
|---|---|
| 80 and above | Strong alignment |
| 60 to 79.9 | Good alignment |
| 40 to 59.9 | Partial alignment |
| Below 40 | Early alignment |

### Evidence levels

| Source | Value |
|---|---|
| Nothing | 0.00 |
| Listed on the profile only (claimed) | 0.25 |
| Project | 0.60 |
| Certification | 0.70 |
| Assessment | 0.85 |
| Verified by the placement cell | 1.00 |

The 0.60 line is the difference between "claimed" and "demonstrated". A skill
under 0.60 is shown as a gap even when it is on the profile.

### Readiness scorecard

Six weighted categories: profile 0.15, resume 0.15, skills 0.25, projects 0.15,
assessments 0.15, interview 0.15. Only categories with data are counted.

### What-if simulator

Same formulas, hypothetical inputs, no writes. It answers "if I added Docker and
raised my CGPA to 9.0, what would change?" - and labels the answer as simulated.
Full arithmetic: `docs/SCORING.md`.

## Example data pack

Everything below is designed to work together: the students' branch strings, the
jobs' requirements and the CGPA floors were chosen so you can see every case -
eligible and strong, eligible but weak, and blocked by a hard rule.

Use these exactly as written the first time. Names, emails and companies are
fictional; do not use real student data while testing.

### Account 1 - administrator

Run once, from the project root:

```bat
C:\xampp\php\php.exe scripts\create-admin.php "Portal Admin" admin@sviet.test "Change-This-Pass-2026"
```

### Accounts 2-4 - recruiters and companies

Register each at `index.php?page=register` with the **Recruiter** radio, then
complete "Company" and tell the admin to approve both.

| # | Recruiter | Email | Password |
|---|---|---|---|
| R1 | Priya Menon | priya.menon@northwind.test | Northwind#2026 |
| R2 | Rohit Verma | rohit.verma@aravalli.test | Aravalli#2026 |
| R3 | Neha Gupta | neha.gupta@brightpath.test | Brightpath#2026 |

| # | Company | Industry | Website | Designation |
|---|---|---|---|---|
| R1 | Northwind Software | Software product | https://northwind.example | Talent Acquisition Lead |
| R2 | Aravalli Analytics | Analytics | https://aravalli.example | People Partner |
| R3 | Brightpath Consulting | Consulting | https://brightpath.example | Campus Lead |

R3 is deliberately left **pending** so you can rehearse the approval flow. Use it
to confirm that a pending recruiter cannot publish a job.

### Company descriptions (paste into "Company")

Northwind Software: "We build scheduling and billing software for mid-size
clinics. Interns join a two-person team, ship to production in their first month
and get a mentor for the whole internship."

Aravalli Analytics: "A ten-person analytics studio working on retail and
logistics data. We hire people who can explain a number, not just compute it."

Brightpath Consulting: "Management consulting for manufacturing clients. Campus
hires start on research and client reporting."

### Jobs

Create with "Recruiter -> Jobs -> Save role". Use **Published** for J1 and J2
(both companies must be approved first) and **Draft** for J3.

**J1 - Northwind Software**

| Field | Value |
|---|---|
| Job title | Graduate Software Engineer |
| Location | Mohali, Punjab |
| Employment type | Full-time |
| Minimum CGPA | 7.00 |
| Eligible branch | "(blank - all branches)" |
| Graduation year | 2027 |
| Application deadline | any date about six weeks away |
| Required skills | PHP, MySQL, Git, JavaScript |
| Preferred skills | Docker, React |
| Status | Published |

Description: "You will work on our scheduling product: PHP and MySQL on the
server, JavaScript in the browser, Git for everything. You will review code in
your first week and own a small feature by week six. We look for curiosity and
clear writing more than a perfect transcript."

**J2 - Aravalli Analytics**

| Field | Value |
|---|---|
| Job title | Data Analyst (Intern) |
| Location | Remote |
| Employment type | Internship |
| Minimum CGPA | 6.50 |
| Eligible branch | "(blank - all branches)" |
| Graduation year | 2027 |
| Required skills | Python, SQL |
| Preferred skills | Pandas, Power BI |
| Status | Published |

Description: "Six-month internship with the retail team. You will clean messy
sales exports, answer questions with SQL, and present one finding a fortnight to
the client lead. Comfort with Python and SQL matters more than dashboards."

**J3 - Aravalli Analytics**

| Field | Value |
|---|---|
| Job title | Junior Data Engineer |
| Location | Mohali, Punjab |
| Employment type | Full-time |
| Minimum CGPA | 8.00 |
| Eligible branch | Computer Science and Engineering |
| Graduation year | 2027 |
| Required skills | Python, SQL, Airflow |
| Preferred skills | AWS, Docker |
| Status | Draft |

Description: "You will own small pipelines end to end: Python extraction, SQL
transforms, Airflow scheduling, and an on-call rota you share with two
engineers."

### Accounts 5-8 - students

Register each at `index.php?page=register` with the **Student** radio, then fill
"Profile -> Save changes" and "Skills -> Save skills".

Sign-in details first - name, email and password for the registration form:

| # | Name | Email | Password |
|---|---|---|---|
| S1 | Aarav Sharma | aarav.sharma@sviet.test | Student#2026a |
| S2 | Diya Kaur | diya.kaur@sviet.test | Student#2026b |
| S3 | Kabir Singh | kabir.singh@sviet.test | Student#2026c |
| S4 | Meera Nair | meera.nair@sviet.test | Student#2026d |

Then the academic fields, on "Profile -> Save changes". Copy the branch strings
character for character: a job restriction compares them exactly.

| # | Degree | Branch | Sem | CGPA | Grad year |
|---|---|---|---|---|---|
| S1 | B.Tech Computer Science | Computer Science and Engineering | 7 | 8.40 | 2027 |
| S2 | B.Tech Information Technology | Information Technology | 7 | 7.10 | 2027 |
| S3 | B.Tech Electronics | Electronics and Communication Engineering | 7 | 6.30 | 2026 |
| S4 | B.Tech Computer Science | Computer Science and Engineering | 7 | 9.10 | 2027 |

Skills (type exactly, comma separated):

| # | Skills |
|---|---|
| S1 | PHP, MySQL, Git |
| S2 | Python, SQL |
| S3 | Java, SQL |
| S4 | Python, SQL, Pandas, Docker |

Bios worth pasting, because they read like a real applicant:

- S1: "Second-year CSE student. I built a lost-and-found board for our hostel
  with PHP and MySQL and I am learning JavaScript by rebuilding it as a small
  single-page app."
- S2: "I like turning spreadsheets into answers. I finished the Google data
  analytics course and built a retail sales dashboard in Python."
- S3: "Electronics student moving towards backend work. Java and SQL so far,
  looking for a team that reviews code seriously."
- S4: "Comfortable with Python, SQL and Pandas. I run a small log-analysis
  pipeline with Docker on my laptop and want to do it properly with Airflow."

Then upload a text-based PDF resume for each (any one-page PDF under 5 MB you
made yourself; a real CV is best for testing "Resume review").

### Projects and certifications (SQL - no UI yet)

The portal reads `student_projects` and `student_certifications` for evidence,
but no screen writes them yet - adding them is a known gap. Run this in
phpMyAdmin's SQL tab after the four students exist. Replace the emails if you
used different ones.

```sql
INSERT INTO student_projects (student_id, title, description, project_url, skills_json)
SELECT id, 'Campus Lost & Found', 'Hostel lost-and-found board built with PHP and MySQL.', '', '["PHP","MySQL"]'
FROM users WHERE email = 'aarav.sharma@sviet.test';

INSERT INTO student_projects (student_id, title, description, project_url, skills_json)
SELECT id, 'Retail Sales Dashboard', 'Cleans a retail export and charts weekly revenue.', '', '["Python","SQL","Pandas"]'
FROM users WHERE email = 'diya.kaur@sviet.test';

INSERT INTO student_projects (student_id, title, description, project_url, skills_json)
SELECT id, 'Log Lakes pipeline', 'Python and Docker pipeline that summarises nginx logs.', '', '["Python","Docker","SQL"]'
FROM users WHERE email = 'meera.nair@sviet.test';

INSERT INTO student_certifications (student_id, title, issuer, issued_on)
SELECT id, 'Google Data Analytics Professional Certificate', 'Google', '2026-06-15'
FROM users WHERE email = 'diya.kaur@sviet.test';

INSERT INTO student_certifications (student_id, title, issuer, issued_on)
SELECT id, 'AWS Certified Cloud Practitioner', 'AWS', '2026-05-02'
FROM users WHERE email = 'meera.nair@sviet.test';
```

A certification only credits a skill when the skill name appears in the
certificate title (`AWS Certified...` credits AWS), so keep titles literal.

### What you should see

These numbers were produced by this repository's own scoring model
(`CareerFitService` + `EvidenceService`, weights from `config/scoring.php`) on
exactly the data above, after the projects and certifications were added. Treat
small differences as a signal that something in your data differs - a branch
spelling, a missing skill - not as a bug.

| Student | J1 Graduate Software Engineer | J2 Data Analyst (Intern) | J3 Junior Data Engineer |
|---|---|---|---|
| Aarav Sharma | eligible, 76.0 Good | eligible, 35.0 Early | eligible, 35.0 Early |
| Diya Kaur | eligible, 35.0 Early | eligible, 92.0 Strong | **blocked**: CGPA 7.10 < 8.00 and branch mismatch (fit would be 70.8) |
| Kabir Singh | **blocked**: CGPA 6.30 < 7.00 and graduation year 2026 != 2027 | **blocked**: CGPA 6.30 < 6.50 | **blocked**: CGPA, branch and year |
| Meera Nair | eligible, 35.0 Early | eligible, 92.0 Strong | eligible, 73.0 Good |

Three deliberate lessons in that table:

1. Aarav is **eligible** for J2 and J3 but scores 35, because he lists none of
   the required skills. Eligibility is not fit.
2. Diya is eligible for J1 but scores 35: no PHP, no JavaScript. Her strongest
   role is J2, where she matches every required skill.
3. Kabir is blocked everywhere by hard rules. The perfect answer to "why can I
   not apply" is on the job card and in the fit explanation.

Skip the project and certification SQL and the same students score lower - for
example Aarav 72.5 instead of 76.0 on J1, and Diya 85.0 instead of 92.0 on J2.
That is the evidence component doing its job.

### Assessments (SQL - no publishing UI yet)

"Practice -> Assessments" only lists assessments with status `published`, and
the admin UI cannot create them yet. Seed one with SQL to test the flow:

```sql
INSERT INTO assessments (title, description, status, created_by)
SELECT 'SQL fundamentals', 'Ten-minute check on joins and grouping.', 'published', id
FROM users WHERE role = 'admin' ORDER BY id LIMIT 1;

INSERT INTO assessment_questions (assessment_id, question, options_json, correct_answer, points, skill)
SELECT id, 'Which clause filters rows before grouping?', '["WHERE","HAVING","ORDER BY","LIMIT"]', 'WHERE', 1, 'SQL'
FROM assessments WHERE title = 'SQL fundamentals';

INSERT INTO assessment_questions (assessment_id, question, options_json, correct_answer, points, skill)
SELECT id, 'Which join keeps unmatched rows from the left table?', '["INNER JOIN","LEFT JOIN","CROSS JOIN","SELF JOIN"]', 'LEFT JOIN', 1, 'SQL'
FROM assessments WHERE title = 'SQL fundamentals';

INSERT INTO assessment_questions (assessment_id, question, options_json, correct_answer, points, skill)
SELECT id, 'What does GROUP BY do?', '["Sorts rows","Combines rows into groups","Deletes duplicates","Adds an index"]', 'Combines rows into groups', 1, 'SQL'
FROM assessments WHERE title = 'SQL fundamentals';

INSERT INTO assessment_questions (assessment_id, question, options_json, correct_answer, points, skill)
SELECT id, 'Which statement is true about NULL?', '["NULL = 0","NULL = empty string","NULL is unknown","NULL sorts first"]', 'NULL is unknown', 1, 'SQL'
FROM assessments WHERE title = 'SQL fundamentals';

INSERT INTO assessment_questions (assessment_id, question, options_json, correct_answer, points, skill)
SELECT id, 'Which command changes a stored value?', '["SELECT","UPDATE","SHOW","DESCRIBE"]', 'UPDATE', 1, 'SQL'
FROM assessments WHERE title = 'SQL fundamentals';
```

Sign in as Diya and open "Practice -> Assessments": five questions, 100% when all
are right, level "Intermediate", and SQL moves to demonstrated at 60% or more.

### Announcement, interview and offer

| To test | Do this | Expected |
|---|---|---|
| Announcement | Admin -> "Comms": title `TCS drive registration closes Friday`, audience `students`, body "Register on the portal before 5 pm Friday. Bring two copies of your resume." | Every student gets a notification in "Alerts". |
| Interview | Recruiter R1 -> "Applicants": move Aarav's application to `shortlisted`. Then "Interviews": pick him, a date/time two days ahead, format `Online`, link `https://meet.example/northwind-aarav`. | Aarav sees the interview on "Applications" and gets a notification. |
| Offer | Admin -> "Placements": choose that application, CTC `6.50`, offer date today, status `offered`. | Aarav sees 6.50 LPA and the status on the dashboard. |
| Approval | Admin -> "Approvals": approve R3's account and company. | Brightpath can now publish; before approval the job button was locked. |

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Parse error naming a service file, or a "PHP upgrade required" page | Apache runs PHP below 8.0 | Check `php -v`; install XAMPP 8.2 or point Apache at a newer PHP folder, restart Apache. |
| "Database connection failed" / blank page on sign-in | MySQL not running, wrong `.env`, or schema not imported | Start MySQL, run `scripts\doctor.php`, import `database\schema.sql`. |
| Doctor says "Schema tables missing: ..." | Only part of the schema was imported | Import the whole `schema.sql` again; it is `CREATE TABLE IF NOT EXISTS`, so it is safe. |
| "Migrations: 4 pending" | Migrations never applied | `php scripts\migrate.php`. The site still works without them, but some panels stay empty. |
| Sign-in says pending / no recruiter sections | Recruiter account not approved yet | Admin -> "Approvals" -> approve the account **and** the company. |
| Publish is locked for a recruiter | Company profile not approved | Approve the company; drafts are allowed while pending, publishing is not. |
| "You do not have permission" on a studio page | Signed in as a non-student | The three studio pages are student-only by design. |
| Resume upload rejected | Not a PDF, larger than 5 MB, or the file is not really a PDF | Re-export as PDF, keep it under 5 MB. |
| "Read the PDF" refuses a file | Scanned image-only or encrypted PDF | Export a text-based PDF from your editor. |
| Skill shows as a gap even though it is on the profile | Claimed only (0.25, below the 0.60 line) | Add a project or certification that names the skill, or pass an assessment. |
| Career studio says it cannot reach the database | `.env` wrong or schema missing | Same fix as the database row above. |
| Copilot answers look repetitive | `AI_ENABLED=false`, so it is the rules engine | That is the default and it is labelled. Add a provider key only if you want the AI path. |
| Fit percentages look low | No required skills listed on the job, or no skills on the profile | Fill both; the notice under the score says which case you are in. |
| Dock covers the footer | A custom stylesheet overrides the clearance variables | Do not override `--dock-clearance`; the dock is sized from the viewport on purpose. |

### Verify the install

```bat
C:\xampp\php\php.exe scripts\doctor.php
C:\xampp\php\php.exe tests\run.php
```

`tests\run.php` is the source-only suite (services, security and workflow
invariants). It does not replace clicking through the portal on MySQL.

## Not built yet

Stated plainly, so nobody promises a screen that does not exist:

- Creating or editing assessments from the admin UI (seed with the SQL above).
- Adding projects, certifications and interview feedback from the UI.
- Placement drives, bulk shortlisting by eligibility, salary on jobs (CTC lives
  on offers), editable `system_settings`, recruiter/TPO notification inboxes.
- Resume virus scanning, and a document preview beyond the browser's own PDF
  viewer.
- Automated browser tests and CI. The JavaScript suites run under jsdom, not a
  real browser.

## Where things live

| Path | Contents |
|---|---|
| `public/index.php` | Public site and the whole student workspace. |
| `public/career.php` | Career studio. |
| `public/resume-review.php` | Resume intelligence. |
| `public/practice.php` | Mock interviews and assessments. |
| `public/recruiter.php`, `public/views/recruiter/` | Recruiter workspace. |
| `public/admin.php`, `public/views/admin/` | TPO / Admin workspace. |
| `app/Services/` | One class per rule: fit, gaps, roadmap, readiness, what-if, resume, interviews, assessments, institution. |
| `app/Data/` | Every SQL statement that is not a page write. |
| `app/Ai/` | Optional provider layer; off by default. |
| `config/scoring.php` | Weights, evidence levels, bands, readiness weights. |
| `database/schema.sql`, `database/migrations/` | Schema and the four additive migrations. |
| `scripts/doctor.php`, `scripts/migrate.php`, `scripts/create-admin.php` | Setup tools. |
| `tests/run.php`, `tests/js/` | Automated checks. |
| `docs/` | This handbook, `SCORING.md`, `CAREER-INTELLIGENCE.md`, `SECURITY.md`, `API.md`, `ARCHITECTURE.md`. |

Read `docs/SCORING.md` before defending any percentage to a student, and
`docs/SECURITY.md` before putting real data in.
