<?php
declare(strict_types=1);
namespace App\Services;
final class RecommendationService {
    public function rank(array $jobs, array $studentSkills, array $student, EligibilityService $eligibility, CareerFitService $fit): array {
        $out=[];
        foreach ($jobs as $job) {
            if (($job['status'] ?? '') !== 'published') continue;
            $required=$job['required_skills'] ?? [];
            $elig=$eligibility->evaluate($student,$job);
            $score=$fit->score($studentSkills,$required,(float)($job['academic_score'] ?? 100),(float)($job['role_score'] ?? 100));
            $out[]=['job'=>$job,'eligibility'=>$elig,'fit'=>$score];
        }
        usort($out,fn($a,$b)=>($b['eligibility']['eligible'] <=> $a['eligibility']['eligible']) ?: ($b['fit']['score'] <=> $a['fit']['score']));
        return $out;
    }
}
