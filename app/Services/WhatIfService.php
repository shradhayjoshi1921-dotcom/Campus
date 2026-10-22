<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Hypothetical profiles. Nothing here writes anything.
 *
 * The caller passes the real profile and the jobs the student can see; this
 * returns two scored snapshots (now and the imagined change) plus the roles
 * whose fit or eligibility would move. Every "what if" is labelled as a
 * simulation in the UI, and improving a number is never described as causing
 * an interview or an offer.
 */
final class WhatIfService
{
    public function __construct(
        private readonly CareerFitService $fit,
        private readonly EligibilityService $eligibility,
        private readonly ScoringModel $model,
    ) {
    }

    /**
     * @param array{profile:array,skills:array<int,string>,evidence:array,jobs:array<int,array>} $state
     * @param array{cgpa?:float,add_skills?:array<int,string>,evidence_for?:array<int,string>,assessment_for?:array<int,string>} $changes
     */
    public function simulate(array $state, array $changes): array
    {
        $profile = $state['profile'] ?? [];
        $jobs = $state['jobs'] ?? [];
        $skills = $state['skills'] ?? [];
        $evidence = $state['evidence'] ?? [];

        $imagined = $profile;
        if (isset($changes['cgpa']) && is_numeric($changes['cgpa'])) {
            $imagined['cgpa'] = max(0, min(10, (float) $changes['cgpa']));
        }
        $addedSkills = array_values(array_filter(array_map('trim', (array) ($changes['add_skills'] ?? []))));
        $imaginedSkills = array_values(array_unique(array_merge($skills, $addedSkills)));

        $imaginedEvidence = $evidence;
        foreach ((array) ($changes['evidence_for'] ?? []) as $skill) {
            $key = mb_strtolower(trim((string) $skill));
            if ($key === '') {
                continue;
            }
            $imaginedEvidence[$key] = [
                'level' => 'project',
                'value' => $this->model->evidenceValue('project'),
                'sources' => ['Simulated project'],
            ];
        }
        foreach ((array) ($changes['assessment_for'] ?? []) as $skill) {
            $key = mb_strtolower(trim((string) $skill));
            if ($key === '') {
                continue;
            }
            $imaginedEvidence[$key] = [
                'level' => 'assessment',
                'value' => $this->model->evidenceValue('assessment'),
                'sources' => ['Simulated assessment result'],
            ];
        }

        $rows = [];
        foreach ($jobs as $job) {
            $before = $this->scoreJob($job, $profile, $skills, $evidence);
            $after = $this->scoreJob($job, $imagined, $imaginedSkills, $imaginedEvidence);
            $delta = round($after['fit']['score'] - $before['fit']['score'], 1);
            $eligibilityChanged = $before['eligibility']['eligible'] !== $after['eligibility']['eligible'];
            if ($delta === 0.0 && !$eligibilityChanged) {
                continue;
            }
            $rows[] = [
                'job' => $job,
                'before' => $before,
                'after' => $after,
                'delta' => $delta,
                'eligibility_changed' => $eligibilityChanged,
            ];
        }
        usort($rows, static fn (array $a, array $b): int => abs($b['delta']) <=> abs($a['delta']));

        $beforeTotal = $this->average($jobs, $profile, $skills, $evidence);
        $afterTotal = $this->average($jobs, $imagined, $imaginedSkills, $imaginedEvidence);

        return [
            'rows' => $rows,
            'before_total' => $beforeTotal,
            'after_total' => $afterTotal,
            'delta' => round($afterTotal - $beforeTotal, 1),
            'simulated_profile' => $imagined,
            'simulated_skills' => array_values(array_diff($imaginedSkills, $skills)),
            'notice' => 'Simulated only. Your saved profile is unchanged, and a higher estimate does not guarantee an interview or an offer.',
        ];
    }

    private function scoreJob(array $job, array $profile, array $skills, array $evidence): array
    {
        $min = (float) ($job['min_cgpa'] ?? 0);
        $cgpa = isset($profile['cgpa']) && $profile['cgpa'] !== '' ? (float) $profile['cgpa'] : null;
        $academic = $cgpa === null ? 0.0 : ($min <= 0 ? 100.0 : min(100.0, round($cgpa / $min * 100, 1)));
        $fit = $this->fit->score($skills, $job['required_skills'] ?? [], $academic, 100.0, [
            'evidence' => $evidence,
            'preferred' => $job['preferred_skills'] ?? [],
        ]);
        return [
            'fit' => $fit,
            'eligibility' => $this->eligibility->evaluate($profile, $job),
        ];
    }

    private function average(array $jobs, array $profile, array $skills, array $evidence): float
    {
        if ($jobs === []) {
            return 0.0;
        }
        $total = 0.0;
        foreach ($jobs as $job) {
            $total += $this->scoreJob($job, $profile, $skills, $evidence)['fit']['score'];
        }
        return round($total / count($jobs), 1);
    }
}
