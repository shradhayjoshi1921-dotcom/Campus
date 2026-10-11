<?php
declare(strict_types=1);
namespace App\Services;
final class SkillGapService {
    public function analyze(array $owned, array $required, array $preferred=[]): array {
        $owned=array_map(fn($v)=>strtolower(trim((string)$v)),$owned);
        $required=array_map(fn($v)=>strtolower(trim((string)$v)),$required);
        $preferred=array_map(fn($v)=>strtolower(trim((string)$v)),$preferred);
        return ['mandatory_missing'=>array_values(array_diff($required,$owned)),'preferred_missing'=>array_values(array_diff($preferred,$owned)),'matched_required'=>array_values(array_intersect($required,$owned))];
    }
}
