<?php
declare(strict_types=1);
namespace App\Services;
final class CareerFitService {
    public function score(array $studentSkills, array $requiredSkills, float $academicScore=100, float $roleScore=100): array {
        $norm=static fn($s)=>strtolower(trim((string)$s));
        $student=array_values(array_unique(array_map($norm,$studentSkills))); $required=array_values(array_unique(array_map($norm,$requiredSkills)));
        $matched=array_values(array_intersect($required,$student)); $missing=array_values(array_diff($required,$student));
        $skillScore=count($required) ? round(count($matched)/count($required)*100,1) : 100.0;
        $academicScore=max(0,min(100,$academicScore)); $roleScore=max(0,min(100,$roleScore));
        $overall=round($skillScore*.60+$academicScore*.25+$roleScore*.15,1);
        return ['score'=>$overall,'components'=>['skills'=>$skillScore,'academic'=>$academicScore,'role'=>$roleScore],'matched_skills'=>$matched,'missing_skills'=>$missing,'notice'=>'A rule-based fit estimate, not a probability of hiring.'];
    }
}
