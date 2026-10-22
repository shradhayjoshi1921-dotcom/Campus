<?php
declare(strict_types=1);
namespace App\Services;
final class CareerFitService {
    /**
     * Score one job against one student's skills.
     *
     * When the employer has not listed any required skills there is nothing to
     * match, and a 100% skill score would be a false claim ("you match every
     * job perfectly"). In that case the skill component is reported as null and
     * the estimate covers only the academic and role components, with a notice
     * explaining why. Callers must render `skills_listed` honestly.
     */
    public function score(array $studentSkills, array $requiredSkills, float $academicScore=100, float $roleScore=100): array {
        $norm=static fn($s)=>strtolower(trim((string)$s));
        $student=array_values(array_unique(array_map($norm,$studentSkills))); $required=array_values(array_unique(array_map($norm,$requiredSkills)));
        $matched=array_values(array_intersect($required,$student)); $missing=array_values(array_diff($required,$student));
        $academicScore=max(0,min(100,$academicScore)); $roleScore=max(0,min(100,$roleScore));
        $skillsListed=count($required)>0;
        if ($skillsListed) {
            $skillScore=round(count($matched)/count($required)*100,1);
            $overall=round($skillScore*.60+$academicScore*.25+$roleScore*.15,1);
            $notice='A rule-based fit estimate, not a probability of hiring.';
        } else {
            $skillScore=null;
            $overall=round($academicScore*.60+$roleScore*.40,1);
            $notice='This employer has not listed required skills yet, so the estimate covers academics and eligibility only.';
        }
        return ['score'=>$overall,'components'=>['skills'=>$skillScore,'academic'=>$academicScore,'role'=>$roleScore],'matched_skills'=>$matched,'missing_skills'=>$missing,'skills_listed'=>$skillsListed,'notice'=>$notice];
    }
}
