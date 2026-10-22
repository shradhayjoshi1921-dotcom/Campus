<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Placement readiness — preparation, not prediction.
 *
 * Six categories, each 0–100 or null when there is nothing to measure. Every
 * category carries the evidence that produced it and one concrete next step,
 * because a number nobody can interrogate is worse than no number at all.
 *
 * Readiness is deliberately separate from (a) official eligibility, which is
 * decided per job by the employer's stated rules, and (b) any claim about
 * whether a student will be placed. Both are shown elsewhere in the product.
 */
final class ReadinessService
{
    public function __construct(private ScoringModel $model)
    {
    }

    /**
     * @param array{profile:array,resume:?array,skills:array<int,array>,evidence:array,projects:array,certifications:array,assessments:array,practice:array,resume_analysis:?array} $data
     * @return array{total:?float,categories:array<int,array<string,mixed>>}
     */
    public function score(array $data): array
    {
        $evidence = $data['evidence'] ?? [];
        $demonstrated = array_filter($evidence, fn (array $row): bool => $row['value'] >= $this->model->demonstratedThreshold());
        $profile = $data['profile'] ?? [];

        $categories = [];
        $categories[] = $this->category(
            'profile',
            'Profile completeness',
            $this->profileScore($profile),
            [
                'Academic details recorded' => trim((string) ($profile['branch'] ?? '')) !== '' && trim((string) ($profile['degree'] ?? '')) !== '',
                'CGPA recorded' => trim((string) ($profile['cgpa'] ?? '')) !== '',
                'Graduation year recorded' => trim((string) ($profile['graduation_year'] ?? '')) !== '',
                'Contact number recorded' => trim((string) ($profile['phone'] ?? '')) !== '',
                'Short introduction written' => trim((string) ($profile['bio'] ?? '')) !== '',
            ],
            'Complete the empty profile fields — recruiters filter on branch, CGPA and graduation year.',
        );
        $categories[] = $this->category(
            'resume',
            'Resume readiness',
            $this->resumeScore($data),
            [
                'PDF uploaded' => !empty($data['resume']),
                'Extracted details reviewed' => !empty($data['resume_analysis']['approved_at'] ?? null),
                'Resume text analysed' => !empty($data['resume_analysis']['extracted_at'] ?? null),
            ],
            'Upload a PDF, then review the extracted details so your profile matches the document.',
        );
        $categories[] = $this->category(
            'skills',
            'Demonstrated skills',
            count($evidence) ? min(100.0, round(count($demonstrated) / max(3, min(8, count($evidence))) * 100, 1)) : null,
            ['Skills with evidence at or above the demonstrated level' => count($demonstrated) . ' of ' . count($evidence)],
            'Add a project or certification against a skill you have claimed, so it stops being a claim alone.',
        );
        $categories[] = $this->category(
            'projects',
            'Relevant projects',
            $this->projectScore($data),
            ['Projects recorded' => count($data['projects'] ?? []), 'Certifications recorded' => count($data['certifications'] ?? [])],
            'Add one project that uses a skill from your target role and link it from your profile.',
        );
        $categories[] = $this->category(
            'assessments',
            'Assessment performance',
            $this->assessmentScore($data['assessments'] ?? []),
            ['Assessments attempted' => count($data['assessments'] ?? [])],
            'Take a short assessment for a skill you claim; the result is evidence your profile cannot fake.',
        );
        $categories[] = $this->category(
            'interview',
            'Interview preparation',
            $this->practiceScore($data['practice'] ?? []),
            ['Practice sessions completed' => count($data['practice'] ?? [])],
            'Run one mock interview for your target role and read the rubric feedback.',
        );

        $weights = $this->model->readinessWeights();
        $active = 0.0;
        $total = 0.0;
        foreach ($categories as &$category) {
            $weight = (float) ($weights[$category['key']] ?? 0.0);
            $category['weight'] = $weight;
            if ($category['score'] !== null) {
                $active += $weight;
            }
        }
        unset($category);
        if ($active > 0) {
            foreach ($categories as $category) {
                if ($category['score'] !== null) {
                    $total += $category['score'] * ($category['weight'] / $active);
                }
            }
        }

        return [
            'total' => $active > 0 ? round($total, 1) : null,
            'band' => $active > 0 ? $this->model->band(round($total, 1)) : 'Not enough information yet',
            'categories' => $categories,
            'notice' => 'Readiness measures your preparation in Campus. It is not a placement prediction and does not decide eligibility.',
        ];
    }

    private function category(string $key, string $label, ?float $score, array $evidence, string $next): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'score' => $score === null ? null : round(max(0, min(100, $score)), 1),
            'evidence' => $evidence,
            'next' => $next,
        ];
    }

    private function profileScore(array $profile): float
    {
        $checks = [
            trim((string) ($profile['branch'] ?? '')) !== '',
            trim((string) ($profile['degree'] ?? '')) !== '',
            trim((string) ($profile['cgpa'] ?? '')) !== '',
            trim((string) ($profile['graduation_year'] ?? '')) !== '',
            trim((string) ($profile['phone'] ?? '')) !== '',
            trim((string) ($profile['bio'] ?? '')) !== '',
        ];
        return round(count(array_filter($checks)) / count($checks) * 100, 1);
    }

    private function resumeScore(array $data): ?float
    {
        if (empty($data['resume'])) {
            return 0.0;
        }
        $analysis = $data['resume_analysis'] ?? null;
        if (!$analysis) {
            return 40.0;
        }
        if (empty($analysis['approved_at'])) {
            return 70.0;
        }
        return 100.0;
    }

    private function projectScore(array $data): float
    {
        $projects = count($data['projects'] ?? []);
        $certs = count($data['certifications'] ?? []);
        return min(100.0, round($projects * 34 + $certs * 16, 1));
    }

    private function assessmentScore(array $assessments): ?float
    {
        if ($assessments === []) {
            return null;
        }
        $best = 0.0;
        foreach ($assessments as $attempt) {
            $best = max($best, (float) ($attempt['percent'] ?? 0));
        }
        return min(100.0, round($best, 1));
    }

    private function practiceScore(array $practice): ?float
    {
        if ($practice === []) {
            return null;
        }
        $scores = array_filter(array_map(static fn ($row): ?float => isset($row['score']) ? (float) $row['score'] : null, $practice));
        if ($scores === []) {
            return min(100.0, count($practice) * 20.0);
        }
        return min(100.0, round((array_sum($scores) / count($scores)) * 0.7 + min(100, count($practice) * 10) * 0.3, 1));
    }
}
