<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Explainable job-to-student alignment.
 *
 * This is an *estimate of alignment*, never a hiring probability, and it never
 * overrides `EligibilityService`: a hard requirement the student fails keeps
 * them ineligible no matter how high the fit is.
 *
 * Four components, each either measurable or explicitly unknown:
 *   skills   — share of the job's required skills the student has;
 *   evidence — how strongly those skills are demonstrated (claims, projects,
 *              certifications, assessments, staff verification);
 *   academic — CGPA relative to the role's stated minimum, 0–100;
 *   role     — branch / graduation-year / eligibility alignment, 0–100.
 *
 * Unknown components are returned as null and their weight is redistributed,
 * so "no information" is never scored as either a strength or a weakness.
 * Weights live in config/scoring.php and are documented in docs/SCORING.md.
 */
final class CareerFitService
{
    private ScoringModel $model;

    public function __construct(?ScoringModel $model = null)
    {
        $this->model = $model ?? new ScoringModel();
    }

    /**
     * @param array<int,string> $studentSkills
     * @param array<int,string> $requiredSkills
     * @param array<string,mixed> $options evidence (EvidenceService map),
     *                                     preferred (array<int,string>),
     *                                     model (ScoringModel)
     */
    public function score(array $studentSkills, array $requiredSkills, float $academicScore = 100, float $roleScore = 100, array $options = []): array
    {
        $model = $options['model'] ?? $this->model;
        $evidence = $options['evidence'] ?? null;
        $preferred = $options['preferred'] ?? [];

        $normalise = static fn ($value): string => mb_strtolower(trim((string) $value));
        $student = array_values(array_unique(array_map($normalise, $studentSkills)));
        $required = array_values(array_unique(array_map($normalise, $requiredSkills)));
        $preferredNormalised = array_values(array_unique(array_map($normalise, $preferred)));
        $matched = array_values(array_intersect($required, $student));
        $missing = array_values(array_diff($required, $student));
        $preferredMatched = array_values(array_intersect($preferredNormalised, $student));

        $academicScore = max(0, min(100, $academicScore));
        $roleScore = max(0, min(100, $roleScore));
        $skillsListed = count($required) > 0;
        $studentKnown = count($student) > 0;

        $components = [
            'skills' => null,
            'evidence' => null,
            'academic' => $academicScore,
            'role' => $roleScore,
        ];

        if ($skillsListed && $studentKnown) {
            $components['skills'] = round(count($matched) / count($required) * 100, 1);
            $components['evidence'] = $this->evidenceScore($required, $evidence, $model);
        }

        [$weights, $total] = $this->weighted($components, $model, $skillsListed);
        $explanation = [];
        foreach ($weights as $component => $weight) {
            $explanation[] = $model->explainComponent($component, $components[$component], $weight);
        }

        $notices = [];
        if (!$skillsListed) {
            $notices[] = 'This employer has not listed required skills, so the estimate covers academics and eligibility only.';
        } elseif (!$studentKnown) {
            $notices[] = 'Your profile lists no skills yet, so skill alignment is unknown rather than zero.';
        }
        $notices[] = 'A rule-based estimate of alignment, not a probability of being hired.';

        return [
            'score' => round($total, 1),
            'band' => $model->band(round($total, 1)),
            'components' => $components,
            'weights' => $weights,
            'matched_skills' => $matched,
            'missing_skills' => $missing,
            'preferred_matched' => $preferredMatched,
            'skills_listed' => $skillsListed,
            'student_known' => $studentKnown,
            'explanation' => $explanation,
            'notice' => $notices[0],
            'notices' => $notices,
        ];
    }

    /** Average evidence value of the job's required skills, 0–100. */
    private function evidenceScore(array $required, ?array $evidence, ScoringModel $model): float
    {
        $total = 0.0;
        foreach ($required as $skill) {
            if (is_array($evidence) && isset($evidence[$skill]['value'])) {
                $total += (float) $evidence[$skill]['value'];
            } elseif (is_array($evidence) && $evidence !== []) {
                // The student has evidence rows; this skill simply is not in them.
                $total += 0.0;
            } else {
                // No evidence data supplied: a listed skill counts as a claim.
                $total += $model->evidenceValue('claimed');
            }
        }
        return round($total / max(1, count($required)) * 100, 1);
    }

    /** @return array{0: array<string,float>, 1: float} */
    private function weighted(array $components, ScoringModel $model, bool $skillsListed): array
    {
        $weights = $skillsListed ? $model->weights() : $model->fallbackWeights();
        $active = 0.0;
        foreach ($components as $key => $value) {
            if ($value !== null && isset($weights[$key])) {
                $active += $weights[$key];
            }
        }
        $used = [];
        $total = 0.0;
        foreach ($components as $key => $value) {
            if ($value === null || !isset($weights[$key]) || $active <= 0) {
                continue;
            }
            $effective = $weights[$key] / $active;
            $used[$key] = $effective;
            $total += $value * $effective;
        }
        return [$used, $total];
    }

    /** The career-fit insights a student reads next to the number. */
    public function nextActions(array $result, array $evidence = []): array
    {
        $actions = [];
        foreach (array_slice($result['missing_skills'], 0, 4) as $skill) {
            $level = $evidence[$skill]['level'] ?? 'none';
            $actions[] = $level === 'none'
                ? ucfirst((string) $skill) . ': add it to your profile, then demonstrate it with a project.'
                : ucfirst((string) $skill) . ': your profile claims it but no evidence supports it yet.';
        }
        if ($result['components']['skills'] === null && $result['skills_listed']) {
            $actions[] = 'Add the skills you already use so alignment can be measured.';
        }
        if ($result['components']['academic'] !== null && $result['components']['academic'] < 100) {
            $actions[] = 'Academic fit is below the role minimum; check the eligibility details before applying.';
        }
        return $actions;
    }
}
