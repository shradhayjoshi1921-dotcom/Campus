# Scoring model

Every number Campus shows a student, a recruiter or the placement cell comes from
this document. If a screen and this file disagree, the file is wrong — fix it in
the same commit.

The weights live in [`config/scoring.php`](../config/scoring.php). A site can
override any of them in `system_settings` without touching code:

| Setting key | Default | Meaning |
|---|---|---|
| `scoring.weights.skills` | `0.45` | Share of a role's required skills the student has |
| `scoring.weights.evidence` | `0.20` | How strongly those skills are demonstrated |
| `scoring.weights.academic` | `0.20` | CGPA relative to the role's minimum |
| `scoring.weights.role` | `0.15` | Branch / graduation-year alignment |
| `scoring.demonstrated_threshold` | `0.60` | Evidence value at or above which a skill counts as demonstrated |

`ScoringModel` normalises the weights, so a partial override cannot push the sum
past 1.0, and it ignores a value that is not a number between 0 and 1.

## Career fit (0–100)

| Component | How it is calculated | Null when |
|---|---|---|
| `skills` | matched required skills ÷ listed required skills × 100 | the job lists no required skills, or the student lists no skills |
| `evidence` | mean evidence value of the required skills, 0–1, × 100 | the job lists no required skills, or the student lists no skills |
| `academic` | `min(100, CGPA ÷ job minimum × 100)`; 100 when the job sets no minimum | the student has no CGPA on file |
| `role` | branch, graduation year and location alignment (100 when the employer set no restriction) | never — it is always known |

A **null** component is dropped and the remaining weights are re-normalised over
the components that could be measured. A missing field therefore lowers
confidence instead of being scored as a fabricated zero.

When a job lists no required skills at all, the model uses
`fallback_weights` (academic 0.55, role 0.45) and says so on screen. Nothing is
scored 100 merely because there was nothing to compare. A perfect 100 requires
a perfect academic match **and** demonstrated evidence.

### Worked example

Student with `PHP`, `SQL`; job requires `PHP`, `Javascript`; academic 80; role 100.

```
skills   = 1 matched / 2 listed            = 50.0   weight .45
evidence = (0.25 + 0.25) / 2 × 100         = 25.0   weight .20   (two claims)
academic = 80                                        weight .20
role     = 100                                       weight .15
fit      = .45×50 + .20×25 + .20×80 + .15×100      = 58.5
```

The evidence component averages over the job's **required** skills only, so the
same student with a project that demonstrates `PHP` (and nothing for
`Javascript`) has evidence 30.0 and a fit of 59.5; with an assessment at 85% on
`PHP` it is 62.0. `tests/run.php` asserts this arithmetic.

## Evidence levels

| Level | Value | Source |
|---|---|---|
| `none` | 0.00 | nothing on record |
| `claimed` | 0.25 | the student listed the skill |
| `project` | 0.60 | a saved project mentions or tags it |
| `certification` | 0.70 | a saved certification mentions it |
| `assessment` | 0.85 | a published assessment was passed at ≥ 60% |
| `verified` | 1.00 | staff verification (reserved; no UI writes it yet) |

**Claimed is not demonstrated.** Every screen that shows a skill shows which of
these it is, because "I listed React once" and "I shipped a React project" are
not the same claim.

## Skill-gap priority

Gaps are ranked by importance, not by a made-up score:

| Importance | When |
|---|---|
| High | a required skill with no evidence, in a role with three or fewer required skills |
| Medium | any other required skill that is missing or only claimed |
| Low | preferred skills that are missing or only claimed |

A skill whose evidence is at or above the demonstrated threshold is dropped from
the gap list entirely — it is not a gap.

## Readiness (0–100)

Six categories, each 0–100 **or null**:

| Category | Default weight | Null when |
|---|---|---|
| Profile completeness | 0.15 | never (it is a count of filled fields) |
| Resume readiness | 0.15 | never (0 means "no PDF") |
| Demonstrated skills | 0.25 | the student has no skills on record |
| Relevant projects | 0.15 | never (a count of projects and certifications) |
| Assessment performance | 0.15 | no assessment has been attempted |
| Interview preparation | 0.15 | no practice session has been recorded |

Nulls are excluded and the remaining weights are re-normalised, exactly like
career fit. Readiness is preparation inside Campus; it is not a placement
probability and it never decides eligibility.

## Bands

| Band | Range |
|---|---|
| Strong alignment | ≥ 80 |
| Good alignment | ≥ 60 |
| Partial alignment | ≥ 40 |
| Early alignment | < 40 |

## Hard eligibility is separate

`EligibilityService` evaluates the employer's stated criteria (minimum CGPA,
branch, graduation year, closing date) **before** any fit score is shown, and no
fit score can override a failed criterion. A role the student is not eligible
for is still listed — as a gap to close, clearly labelled.

## What these numbers are not

- Not a probability of being hired, shortlisted or placed.
- Not a machine-learning model. There is no training data, no hidden weights and
  no black box: every component and weight is printed next to the score.
- Not comparable across students as a ranking. The placement cell sees cohort
  averages, never a leaderboard.

## Tests

`php tests/run.php` asserts the weights, the null handling, the 85-point cap for
a claim-only match, the 60% evidence line, the gap priority order and the
"insufficient data" behaviour of every banded metric. It does not need a
database.
