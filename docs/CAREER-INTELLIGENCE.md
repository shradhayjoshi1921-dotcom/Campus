# Career intelligence — the four phases

This document is the delivery record for the intelligence work: what exists,
where it lives, how to apply it, what was verified and **what could not be
verified in this environment**. Read the last section before trusting a score.

The design rule behind every phase: *a number a student cannot interrogate is
worse than no number at all.* Every score prints its components and weights,
every missing input is `null` rather than a fabricated zero, every estimate is
labelled, and every AI-touchpoint has a deterministic fallback that needs no
external provider.

---

## Phase 1 — Core intelligence

| Piece | File | What it does |
|---|---|---|
| Scoring model | `config/scoring.php`, `app/Services/ScoringModel.php` | One place for weights, evidence levels, bands and readiness weights; reads `system_settings` overrides. |
| Evidence | `app/Services/EvidenceService.php` | Turns profile skills, projects, certifications, assessment results and staff verification into a `skill => {level, value, sources}` map. |
| Career fit | `app/Services/CareerFitService.php` | Four weighted components, null-aware, with a per-component explanation. |
| Skill gaps | `app/Services/SkillGapService.php` | Mandatory/preferred gaps plus `prioritise()` (importance 1–3, why, evidence summary) and `compare()` for multi-target roles. |
| Recommendations | `app/Services/RecommendationService.php` | Hard eligibility first, then fit; groups into best / eligible / prepare / stretch roles. |
| Roadmaps | `app/Services/RoadmapService.php` | `plan()` builds a week-by-week schedule from the real gaps, with task types, hour estimates and study resources. |
| Readiness | `app/Services/ReadinessService.php` | Six categories with evidence and one next step each. |
| What-if | `app/Services/WhatIfService.php` | Simulates CGPA/skill/evidence changes; writes nothing. |
| Career graph | `app/Services/CareerGraphService.php` | Role → skills → evidence → resources, with per-skill status. |
| Data layer | `app/Data/StudentData.php`, `app/Data/CareerStore.php` | All reads and writes, every statement prepared and student-scoped. |
| UI | `public/career.php` (rewritten), `public/views/partials/studio-*.php`, `public/assets/css/intelligence.css` | Readiness, grouped recommendations, target role with graph, saved roadmap with task status, what-if, copilot. |

**Migration safety:** Phase 1 writes only to tables that already exist in
`database/schema.sql` (`career_fit_results`, `skill_gap_results`,
`learning_roadmaps`, `roadmap_items`, `career_targets` after migration). Every
write is inside a `try` that logs and continues, so a cache failure never breaks
a page.

---

## Phase 2 — Resume and recruiter tools

| Piece | File | What it does |
|---|---|---|
| PDF text extraction | `app/Services/ResumeTextService.php` | Dependency-free FlateDecode stream parser; refuses encrypted, oversized, non-PDF or image-only files **with a reason** instead of returning an empty success. |
| Resume analysis | `app/Services/ResumeAnalysisService.php` | Section-aware extraction, word-boundary skill comparison with quoted context, formatting/contact heuristics, and a bullet rewrite that cannot invent a metric. |
| Job description drafter | `app/Services/JobDescriptionService.php` | Rules-based field extraction from pasted prose; unknown fields are listed in `missing[]`. |
| Interview kit | `app/Services/InterviewKitService.php` | Technical, coding, aptitude, behavioural and role questions with 1–4 rubrics. |
| Student UI | `public/resume-review.php` | Analyse → review → tick → approve. Approval writes claims plus a resume evidence row; nothing becomes "verified". |
| Recruiter UI | `public/recruiter.php?section=ai`, `public/views/recruiter/ai-tools.php` | Draft a description, build a kit for one of your own jobs, compare an applicant's stored resume with that job's requirements. Read-only; each resume read is audited as `resume.analysed`. |

**Migration safety:** `resume_analyses` is created by
`database/migrations/2026-10-22-ai-intelligence.sql`. The page degrades to "no
analysis yet" when the table is absent; it never creates rows for other students
and it re-reads the PDF from `storage/uploads/resumes/` under the same ownership
rules as the download route.

---

## Phase 3 — Student AI coaching

| Piece | File | What it does |
|---|---|---|
| AI service layer | `app/Ai/*` | `AiService` with a `NullProvider` default, an HTTP provider for an optional OpenAI-compatible endpoint, hourly quotas, 12 000-character payload cap, and `ai_usage_logs` rows that contain no prompt text. |
| Copilot | `app/Services/CopilotService.php` | Rules-first intent routing over the student's own facts; an optional provider may rephrase that answer, never extend it. |
| Mock interview | `app/Services/MockInterviewService.php` | Up to four questions; five named criteria; per-criterion notes and an improved skeleton. No voice, face or emotion analysis. |
| Assessments | `app/Services/AssessmentService.php` | Scoring, cautious levels (fewer than five questions ⇒ "Not enough evidence"), evidence rows, and the 60% demonstration line. |
| UI | `public/practice.php` | Session-based mock interview (stored when the migration has run, browser-session-only when it has not) and the published assessments. |
| Copilot UI | `public/career.php` | Question box, last six exchanges kept in the session, source label (`rules` or `ai`). |

**Privacy decisions:** no camera, microphone, keystroke or emotion data is
requested, stored or inferred; the mock interview is text only. The API key is
read server-side from `.env` via `Env::get()` and never rendered.

---

## Phase 4 — Institutional intelligence

| Piece | File | What it does |
|---|---|---|
| Aggregation | `app/Data/InstitutionReport.php` | One read-only pass: students, resumes, analyses, skills, evidence, projects, certifications, attempts, practice, requirements, applications. |
| Analytics | `app/Services/InstitutionService.php` | Readiness by branch, skill demand with co-occurrence, training gaps ranked by affected students, employer coverage, monthly volume, and review flags. |
| UI | `public/admin.php?section=intelligence`, `public/views/admin/intelligence.php` | Cohort report for TPO/admin. No student name is rendered. |
| Nav | `public/views/admin/layout-end.php` | Ninth dock tab, "Insights". |

**Honesty rules encoded in code:** a trend needs at least six periods or it says
"insufficient" and shows no direction; company coverage is a share, not a
promise; every anomaly says it is a review prompt, not a finding; a branch with
no measured student shows a dash, never 0%.

---

## Migrations

Three additive files, applied in name order by one runner:

| File | Adds |
|---|---|
| `database/migrations/2026-10-22-ai-intelligence.sql` | `schema_migrations`, `skill_evidence`, `resume_analyses`, `ai_usage_logs`, `practice_sessions`, `practice_answers`, `career_targets` |
| `database/migrations/2026-10-22-roadmap-columns.sql` | `roadmap_items.week_no`, `task_type`, `title`, `resource` |
| `database/migrations/2026-10-22-assessment-skill.sql` | `assessment_questions.skill` |

```bash
php scripts/migrate.php --status   # what has been applied
php scripts/migrate.php            # apply what is pending
```

Safety properties:

- `CREATE TABLE IF NOT EXISTS` everywhere; `ALTER TABLE … ADD COLUMN` is
  tolerated when the column already exists (MySQL error 1060 is treated as
  "already applied").
- A file is recorded in `schema_migrations` only after its last statement
  succeeds; a failure leaves it pending, and the next run repeats it harmlessly.
  No transaction wraps a file on purpose — MySQL commits DDL implicitly, so
  wrapping it would only create the illusion of atomicity.
- No `DROP`, no `TRUNCATE`, no `DELETE`, no data rewrite. `tests/run.php` asserts
  this against the files.
- Existing tables are untouched apart from the four nullable/defaulted roadmap
  columns and the one nullable `skill` column.

The application still works without the migrations: every optional table read
returns empty and the UI says which feature is waiting for them.

### PHP version

The intelligence layer needs **PHP 8.0 or newer** — the whole application does.
Constructor promotion, `match` and `str_contains` are 8.0 features; promoted
`readonly` properties and `never` return types would have needed 8.1, so they
were replaced with plain promoted properties and `void` when this layer was
reviewed against a stock XAMPP 8.0 build. `bootstrap/app.php` checks
`PHP_VERSION_ID` before it includes anything else and prints a readable
"PHP upgrade required" page instead of a raw parse error, and
`scripts/doctor.php` reports the version the CLI sees.

### Reaching the studio

`career.php`, `resume-review.php` and `practice.php` are standalone student
scripts with their own `Security::requireRole(['student'])` guard. They are
linked from the bubble menu, from the dashboard and from each other, and
`index.php?page=career|resume-review|practice` answers with a 302 to the real
file on `GET` (a `POST` is never redirected, so no form body is silently
dropped).

---

## Configuration

1. **Scoring** — `config/scoring.php`; per-site overrides in `system_settings`
   as `scoring.weights.skills = 0.5` (values outside 0–1 are ignored).
2. **AI (optional)** — `.env`:

   ```dotenv
   AI_ENABLED=false
   AI_PROVIDER=openai
   AI_API_KEY=
   AI_BASE_URL=https://api.openai.com/v1
   AI_MODEL=gpt-4o-mini
   AI_TIMEOUT=12
   ```

   With `AI_ENABLED=false` every AI-adjacent feature still works from rules.
   Hourly defaults: copilot 40, resume 12, roadmap 12, interview 20,
   job description 20, insights 20.
3. **Storage** — resumes stay at `storage/uploads/resumes/` under the existing
   upload validation; extraction reads that same file.

---

## Functional status

| Feature | Status | Verified by |
|---|---|---|
| Career fit + explanation | Implemented | 3 unit tests (`tests/run.php`) |
| Skill gaps + priority | Implemented | unit test |
| Recommendations + grouping | Implemented | existing tests (unpublished jobs excluded) |
| Roadmap generation | Implemented | unit test (6 weeks, task types, hours) |
| Readiness scorecard | Implemented | unit test (nulls preserved) |
| What-if simulator | Implemented | unit test (no writes, notice, eligibility flip) |
| Career graph | Implemented | unit test (demonstrated vs missing) |
| Resume extraction | Implemented, unproven on real PDFs | parser reviewed; no sample PDF in the repo |
| Resume approval | Implemented | code path + CSRF/ownership review |
| Job description drafter | Implemented | unit test |
| Interview kit + mock coach | Implemented | unit test |
| Assessments | Implemented | unit test (levels, 60% line) |
| Copilot | Implemented (rules) | unit test path via service; AI path untested without a key |
| Institutional report | Implemented | unit test on `InstitutionService`; DB aggregation reviewed |
| Migrations | Written, **never executed** | no MySQL in this sandbox |
| UI rendering | Rendered against fixture data | PHP-WASM smoke render (below); still no browser, so pixels are unverified |

### What was actually run here

- `php -l` (PHP-WASM 8.x) on every changed and new PHP file: clean.
- `tests/run.php`: **76 passed, 0 failed** (was 62 before this work; 14 new
  tests cover the model, evidence, gaps, roadmap, what-if, readiness, resume
  analysis, JD draft, interview kit, assessments, institution service, career
  graph, the studio routing/menu wiring and the security/migration invariants).
- `tests/js/dock-geometry.test.js`: PASS with the new dock tab counts
  (student 6, recruiter 6, admin 9), 15 viewport/portal combinations and the
  continuous 1536→360 sweep.
- `tests/js/liquid-glass.test.js`: 17 scenarios PASS.
- `tests/js/student-nav.test.js`: 7 scenarios PASS.
- `css-tree` parse of all eight stylesheets: OK.
- **Template smoke render.** In a throwaway copy of the repo (nothing written
  back), `Database::connection()` was stubbed and the data layer replaced with
  fixtures, then every new surface was rendered through PHP-WASM:
  - `career.php` — 53 KB, no warnings, with recommendations, target panel,
    career graph, fit history, saved 3-week roadmap, what-if table and copilot;
  - `career.php?sim=1&sim_skill=Docker&sim_cgpa=9.0` — simulation table with
    before/after fit and an eligibility flip, plus the "Simulated only" notice;
  - `career.php` POST `ask_copilot` — a rules answer quoting the student's own
    application;
  - `resume-review.php` — recognised fields, review checklist, POST compare
    against a role with matched/missing skills;
  - `practice.php?session=1` and `?session=1&show=0` — questions, rubric
    feedback, "read the feedback";
  - `practice.php?tab=assessments` POST submit — 80% scored, "Intermediate",
    per-question result table;
  - `views/recruiter/ai.php` in all three tool states (draft present/absent, kit
    present/empty, compare present/failed, company missing);
  - `views/admin/intelligence.php` populated, empty and `null`.
  That run also caught a real bug: the studio shell's `foreach ($studioNav as
  $key => $tab)` leaked `$tab`, so `practice.php` rendered the assessments panel
  instead of the interview panel. The loop variables are now namespaced, and the
  `?show=` flag no longer displays feedback when it is absent.

### What was **not** run, and why

- **No MySQL/MariaDB binary** in this sandbox, so the migrations, the SQL and
  every query in `StudentData`, `CareerStore` and `InstitutionReport` are
  reviewed and lint-checked but not executed. Treat the first local run as a
  test, not a formality.
- **No browser** (missing `libnss3`/`libnspr4`), so layout, contrast and the dock
  were reasoned from the CSS tokens and the geometry harness, not seen.
- **No AI provider key**, so the `ai` branch of the copilot is exercised only by
  code review. The rules branch is what runs by default.

---

## Permission and security checks

| Check | Result |
|---|---|
| Career studio, resume review, practice are `Security::requireRole(['student'])` | asserted in `tests/run.php` |
| Every POST verifies CSRF before any write | asserted for all three pages |
| Readiness/recommendations scoped to `student_id` from the session | every statement in `StudentData`/`CareerStore` |
| Roadmap task status checks ownership **in the WHERE clause** | `CareerStore::setTaskStatus` joins `learning_roadmaps` |
| Assessment attempt submit re-checks ownership under `FOR UPDATE` | `CareerStore::submitAttempt` |
| Practice answers only for the student's own session | `practiceSession()` filters on `student_id` |
| Recruiter AI tools limited to that recruiter's company | joins `jobs.company_id` |
| Resume comparison audited | `audit_logs` action `resume.analysed` |
| Admin intelligence is TPO/admin only and names no student | asserted in tests |
| API key never in HTML/JS/views | asserted in tests |
| Migrations are additive only | asserted in tests |
| No student code executed | no `eval`, no shell, no plugin execution |

---

## Manual test script

Prerequisites: a local MySQL, `.env` filled in, `database/schema.sql` imported,
then `php scripts/migrate.php`.

**Student**

1. Register/sign in as a student. Complete the profile (branch, CGPA, graduation
   year, phone, bio) and add two skills.
2. Open `career.php` from the bubble menu (**Career insights**). Confirm the readiness
   categories with no data show "Nothing to measure yet" rather than 0%.
3. As a recruiter (approved company), publish a job with required and preferred
   skills. Refresh the student page: the role appears under a recommendation
   group with a fit %, a band and "Why this score" listing components and
   weights.
4. If the job's minimum CGPA is above the student's, confirm the card says
   eligibility is not met even when the fit % is high.
5. Choose the role as a target. Confirm the gap list separates `missing` from
   `claimed`, and the career graph marks each skill.
6. Press "Generate and save a six-week plan". Change a task's status to
   Completed, reload, and confirm it persisted.
7. Run the what-if simulator with an added skill. Confirm the numbers change,
   the notice says it is simulated, and the saved profile is unchanged.
8. Ask the copilot "where do my applications stand?" — the answer must quote the
   student's own applications only.
9. Upload a text-based PDF on the profile page, open `resume-review.php`, press
   "Read the PDF". Confirm extracted fields appear, tick two skills, approve, and
   check the profile now lists them as claims. Try a scanned/encrypted PDF and
   confirm the refusal message says why.
10. Open `practice.php`, start a session, answer one question, and confirm five
    rubric criteria are shown with notes. Check that no audio/video is requested.
11. Open the Assessments tab. If none are published, confirm the empty state
    says so. If one is published with fewer than five questions, confirm the
    result says "Not enough evidence".

**Recruiter**

12. `recruiter.php?section=ai` → Draft: paste a role description; confirm fields
    appear and missing ones are named. Confirm nothing was published.
13. Build an interview kit for a job with skills; confirm rubrics appear.
14. Compare an applicant's resume; confirm matched/missing skills with quoted
    lines, and that the audit log gained `resume.analysed`.

**TPO / admin**

15. `admin.php?section=intelligence`: confirm the readiness table shows a dash for
    an unmeasured branch, training gaps are ranked by student count, the trend
    says "insufficient" with fewer than six months, and no student name appears.
16. Confirm the dock still fits at 1536 / 1024 / 768 / 480 / 360 px in light and
    dark, and that the footer is reachable above the dock.

---

## Known limitations

- The trend is descriptive only; there is no forecasting model and there will
  not be one until there is enough clean history.
- `skill_evidence` is only written by resume approval today. Projects,
  certifications and assessments contribute through `EvidenceService` at read
  time; persisting those rows is a follow-up, not a claim made here.
- Staff verification (`verified`, value 1.0) has no UI yet.
- Adaptive question selection (choosing the next question from the last answer)
  is not implemented; sessions are a fixed four-question rubric.
- The AI provider path is optional and unproven in this sandbox; the rules path
  is the product default.
- Tier 2 auth hardening (throttling, lockout, `auth.login`/`auth.logout` audit
  events, TPO password reset) is still open, as recorded in `docs/UPGRADE-PLAN.md`.

## Related documents

- [`docs/SCORING.md`](SCORING.md) — every weight and worked arithmetic.
- [`docs/API.md`](API.md) — routes and authorisation for the new pages.
- [`docs/SECURITY.md`](SECURITY.md) — the wider security posture.
- [`docs/UPGRADE-PLAN.md`](UPGRADE-PLAN.md) — what still has to happen before
  this is a production system.
