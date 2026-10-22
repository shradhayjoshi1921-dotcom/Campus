# Campus — audit and AI upgrade plan

Written after reading the codebase as it stands at `2e3273a` (PR #17 merged).
This is the plan the following phases are executed against; each phase is
implemented, tested and merged before the next one starts.

## 1. What exists today

### Entry points and routing

| File | Role | Routing |
| --- | --- | --- |
| `public/index.php` | public + student | one `?page=` switch (`home`, `login`, `register`, `profile`, `jobs`, `applications`, `notifications`, `dashboard`, `career.php`, `resume` stream, `forbidden`) |
| `public/career.php` | student | career intelligence, own layout, own stylesheet |
| `public/recruiter.php` | recruiter | `?section=` (overview, company, jobs, applicants, interviews) with in-place swaps |
| `public/admin.php` | tpo + admin | `?section=` (overview, approvals, students, jobs, placements, reports, comms, governance) |
| `scripts/create-admin.php` | CLI | first admin only; no public admin signup |

Shared: `bootstrap/app.php` (env, session, autoload, `asset_version()`),
`app/Core/{Env,Database,Security}.php`, `app/Services/*` (pure rule engines),
`public/views/partials/glass-defs.php`, one `theme.css` + one
`liquid-glass.css` + one `liquid-glass.js` for all three docks.

Auth: one sign-in form, server reads the role and 303-redirects. Passwords use
`password_hash`/`password_verify`; session id rotates on login; strict mode,
HttpOnly/SameSite cookies; CSRF on every POST; PDO prepared statements
throughout; output escaped via `Security::escape`/`e()`.

### Career intelligence that exists

- `EligibilityService` — deterministic checks against `min_cgpa`, branch and
  graduation year. Missing data is reported as *missing*, not as a pass.
- `CareerFitService` — weighted score (skills 60 %, academic 25 %, role 15 %),
  matched/missing skill lists. Since PR #17 an empty requirement list no longer
  scores 100 %; the skill component is reported as `null` with a notice.
- `SkillGapService` — mandatory vs preferred gaps.
- `RecommendationService` — ranks published jobs by eligibility first, fit
  second.
- `RoadmapService` — per-skill practice task + project idea, in memory.
- `public/career.php` — renders all of the above plus a what-if skill field.

All of it is **computed per request and never stored**. Weights are literals
inside the class, not configurable.

### Schema: 31 tables, and how much of each is used

Fourteen tables are referenced by no application code at all:

`student_certifications`, `student_projects`, `job_eligibility_rules`,
`assessments`, `assessment_questions`, `assessment_attempts`,
`assessment_answers`, `interview_feedback`, `career_fit_results`,
`skill_gap_results`, `learning_roadmaps`, `roadmap_items`, `system_settings`
and (only through the admin CSV importer) nothing else.

This matters: the brief lists "skill evidence, career-fit assessments,
roadmap tasks, assessment questions, interview practice sessions, AI usage
logs" as *possible* new tables. Most of those already exist here and are the
right shape. The plan reuses them instead of adding parallel tables — only
`ai_*` logging and resume extraction genuinely need new tables.

### Real gaps found

1. **No AI layer of any kind.** No provider, key, timeout, retry or log. Every
   "intelligence" feature is a deterministic rule (`career.php` says so, which
   is honest, but the product calls itself AI-powered).
2. **Nothing is persisted.** No fit history, no roadmap progress, no assessment
   attempt, no interview feedback — so "progress over time" is impossible.
3. **Skill evidence is binary.** `student_skills.proficiency` and `.verified`
   exist; nothing writes `verified`, and no code reads `proficiency`. A student
   adding a skill is indistinguishable from one who has demonstrated it.
4. **Resume is a PDF blob.** No text extraction, no review, no comparison with
   a job.
5. **No readiness score, no career graph, no mock interview, no copilot.**
6. **Admin has no analytics**: no skill-demand view, no readiness map, no
   training-gap detection, no anomaly review queue.
7. **Recruiter has no candidate matching, no JD assistant, no interview
   question generation** — and interview feedback has a table but no UI.
8. **Notifications are student-only** even though recruiters/TPOs receive them.
9. **Audit coverage is partial**: recruiter/admin actions are logged, student
   and auth actions are not (no sign-in, no sign-out, no failed attempt).
10. **No rate limiting** on sign-in or on any future AI endpoint.
11. **Dock geometry was desktop-first** (fixed 820/960 px capsules and a
    negative footer margin that pulled the footer under the glass). Fixed in
    this phase — see §3.

### Security posture

Solid base (prepared statements, CSRF, hashed passwords, scoped resume
downloads with an audit row since #17, uploads outside the document root,
PDF signature + MIME + size checks). Missing before production: rate limiting,
password reset, CSP/security headers, complete auth audit, and — once AI is
added — egress of user text to a third party, which needs an explicit,
configurable, minimal-data-provider contract.

## 2. Design rules for every phase

- **Deterministic core stays deterministic.** Eligibility, permissions, score
  arithmetic, state transitions and validation never call a model.
- **AI is additive and optional.** Every feature works, in a degraded but
  useful form, with no provider configured. No feature may block on a network
  call to render a page.
- **Nothing invented.** No fake employers, jobs, deadlines, CTCs, counts or
  credentials. Missing data is shown as missing.
- **Explain every number.** Each score ships the inputs and the arithmetic that
  produced it, and is labelled an estimate, never a prediction.
- **Fairness.** No protected characteristics in any query, score, sort or
  prompt. Candidate matching uses job-relevant evidence only and never
  auto-rejects; a human always decides.
- **Privacy.** Send the minimum text to a provider, never a whole row, never
  another user's data; keep keys server-side in `.env` only.
- **Reuse the schema** listed above before adding a table.

## 3. Phase 0 — navigation and layout (this PR)

Responsive liquid-glass dock and footer clearance, all portals:

- One set of `--dock-*` tokens in `liquid-glass.css` drives every capsule:
  `clamp()` for gutter, bottom offset, padding, gap, tab height, icon, label and
  badge; `--dock-max` per portal; `--dock-clearance` derived from the real
  shell height.
- No tab has a fixed `min-width` any more, so five tabs divide whatever width
  the window has and eight admin tabs stay inside it. Labels compact away
  (admin ≤720 px, student ≤380 px, short viewports) rather than overflow.
- The footer's negative top margin is gone; content ends, then the footer, then
  `--dock-clearance` of guaranteed empty space.
- The engine coalesces resize into one measurement per frame, also listens to
  `visualViewport` (split-screen, on-screen keyboard), and keeps its pill floor
  below the narrowest tab so the bubble can never cover a neighbour.

Verified by `tests/js/dock-geometry.test.js`, which resolves the real token
values at 1536 / 1024 / 768 / 480 / 360 px and sweeps 1536→360 continuously,
and by a resize scenario in `tests/js/liquid-glass.test.js`.

## 4. Phase 1 — core intelligence (next)

1. `SkillWeights`/`config` for scoring: weights, academic curve, evidence
   multipliers — documented in `docs/SCORING.md`, overridable per install.
2. Skill evidence model on the existing `student_skills` row
   (`proficiency`, `verified`) plus project/certification evidence from
   `student_projects` / `student_certifications`; "added" ≠ "demonstrated".
3. Persist fit and gap results in the existing `career_fit_results` /
   `skill_gap_results` tables with a computed-at stamp, so history exists and
   recalculation can be triggered when a profile or job changes.
4. Skill-gap prioritisation by job importance, with "why it matters".
5. Recommendation categories: best matches, eligible, prepare-first, stretch,
   recently added — from real rows, with reasons per card.
6. Roadmap persistence in `learning_roadmaps` / `roadmap_items`: weekly
   milestones, editable schedule, task completion, next-task suggestion.
   Generated by rules when no provider is configured.
7. Student-facing UI for all of it, replacing the single `career.php` page with
   sections that use the existing dock and theme.

## 5. Phase 2 — resume and recruiter tools

1. PDF text extraction (server-side, no shell execution), stored as a review
   record separate from the verified profile until the student approves it.
2. Resume ↔ job comparison with evidence-level explanations.
3. Recruiter candidate matching per vacancy, with explicit eligibility shown
   separately from fit and no automatic rejection.
4. Job-description assistant producing a structured draft the recruiter edits
   and publishes through the existing `save_job` path.
5. Interview questions + rubric from the job's real skills; feedback form that
   writes `interview_feedback` and never auto-scores a candidate.

## 6. Phase 3 — student coaching

Career copilot (context-limited to the signed-in student's own data), text mock
interview with stored sessions, assessments on the existing
`assessment_*` tables (timed/untimed, auto-scored objective questions, no
arbitrary code execution), readiness scorecard with per-category evidence, and
the what-if simulator (never writes the profile unless explicitly saved).

## 7. Phase 4 — institutional intelligence

Aggregate readiness map, skill-demand analysis over real job descriptions,
training-gap detection, company–college matching, a permission-aware analytics
copilot built on **fixed, reviewed queries** (never model-generated SQL), and
descriptive statistics — with forecasting only if the data volume supports it,
and an anomaly review queue that flags for humans and accuses nobody.

## 8. AI service layer (built in Phase 1, used by 2–4)

`app/Ai/`:

- `AiProvider` interface: `complete(AiRequest): AiResult`.
- `NullProvider` — deterministic fallback, always available.
- `HttpProvider` — OpenAI-compatible chat completions; base URL, model, key,
  timeout and token cap from `.env`; no SDK, no new dependency.
- `AiService` — validates the request, enforces per-user/per-feature rate
  limits and payload size, strips anything not explicitly allowed, validates
  JSON output against a declared shape, logs usage/errors to `ai_usage_logs`
  (feature, model, tokens, latency, outcome — no prompt text), and falls back
  to the deterministic path on any failure.
- Feature flags: a feature with no provider silently uses its rule-based path
  and says so in the UI.

## 9. How to apply migrations safely

Every phase that adds a table ships a numbered file in `database/migrations/`.
They are additive only (`CREATE TABLE IF NOT EXISTS`, `ADD COLUMN` guarded by an
`information_schema` check): never `DROP`, never `TRUNCATE`, never a data
rewrite in the same statement. `scripts/migrate.php` (Phase 1) applies them in
order, records what it applied in `schema_migrations`, and is safe to re-run.
