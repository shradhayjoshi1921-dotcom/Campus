<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Turns the student's real open jobs into a small set of honest categories.
 *
 * Nothing is invented: every card is a row from `jobs` joined to an approved
 * company, and eligibility is decided by `EligibilityService` before any fit
 * number is shown. A high fit never promotes an ineligible role into "eligible".
 */
final class RecommendationService
{
    public const CATEGORIES = [
        'best' => 'Best matches',
        'eligible' => 'Eligible opportunities',
        'prepare' => 'Jobs requiring preparation',
        'stretch' => 'Stretch opportunities',
        'recent' => 'Recently added',
    ];

    public function rank(array $jobs, array $studentSkills, array $student, EligibilityService $eligibility, CareerFitService $fit): array
    {
        $out = [];
        foreach ($jobs as $job) {
            if (($job['status'] ?? '') !== 'published') {
                continue;
            }
            $elig = $eligibility->evaluate($student, $job);
            $score = $fit->score(
                $studentSkills,
                $job['required_skills'] ?? [],
                (float) ($job['academic_score'] ?? 100),
                (float) ($job['role_score'] ?? 100),
                ['evidence' => $job['evidence'] ?? null, 'preferred' => $job['preferred_skills'] ?? []],
            );
            $out[] = [
                'job' => $job,
                'eligibility' => $elig,
                'fit' => $score,
                'reasons' => $this->reasons($job, $score, $elig),
                'category' => $this->categorise($job, $score, $elig),
            ];
        }
        usort($out, static fn (array $a, array $b): int => ($b['eligibility']['eligible'] <=> $a['eligibility']['eligible']) ?: ($b['fit']['score'] <=> $a['fit']['score']));
        return $out;
    }

    public function categorise(array $job, array $fit, array $eligibility): string
    {
        if (!$eligibility['eligible'] && ($eligibility['failed'] ?? []) !== []) {
            return 'prepare';
        }
        if ($eligibility['eligible'] && $fit['score'] >= 60) {
            return 'best';
        }
        if ($eligibility['eligible']) {
            return 'eligible';
        }
        return $fit['score'] < 25 ? 'stretch' : 'prepare';
    }

    private function reasons(array $job, array $fit, array $eligibility): array
    {
        $reasons = [];
        if ($eligibility['eligible']) {
            $reasons[] = 'You meet the published eligibility criteria.';
        } else {
            foreach (array_merge($eligibility['failed'] ?? [], $eligibility['missing'] ?? []) as $reason) {
                $reasons[] = 'Eligibility: ' . $reason . '.';
            }
        }
        if ($fit['matched_skills']) {
            $reasons[] = 'Matched skills: ' . implode(', ', array_slice($fit['matched_skills'], 0, 5)) . '.';
        }
        if ($fit['missing_skills']) {
            $reasons[] = 'Not yet listed on your profile: ' . implode(', ', array_slice($fit['missing_skills'], 0, 5)) . '.';
        }
        if (!$fit['skills_listed']) {
            $reasons[] = 'The employer listed no required skills, so alignment is estimated from academics only.';
        }
        return $reasons;
    }

    /** Split ranked rows into the categories the UI shows, dropping empties. */
    public function group(array $ranked): array
    {
        $groups = [];
        foreach ($ranked as $row) {
            $groups[$row['category']][] = $row;
        }
        $ordered = [];
        foreach (self::CATEGORIES as $key => $label) {
            if (!empty($groups[$key])) {
                $ordered[$key] = ['label' => $label, 'rows' => $groups[$key]];
            }
        }
        return $ordered;
    }
}
