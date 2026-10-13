<?php
declare(strict_types=1);
namespace App\Services;
final class EligibilityService {
    /** @return array{eligible:bool,passed:array,failed:array,missing:array} */
    public function evaluate(array $student, array $job): array {
        $passed=[]; $failed=[]; $missing=[];
        if (!isset($student['cgpa']) || $student['cgpa']==='') $missing[]='CGPA is not provided';
        elseif ((float)$student['cgpa'] < (float)($job['min_cgpa'] ?? 0)) $failed[]='CGPA below minimum of '.(float)($job['min_cgpa'] ?? 0);
        else $passed[]='CGPA meets minimum';
        $branch = trim((string)($job['eligible_branch'] ?? ''));
        if ($branch !== '' && $branch !== 'Any') {
            if (empty($student['branch'])) $missing[]='Branch is not provided';
            elseif (strcasecmp((string)$student['branch'], $branch)!==0) $failed[]='Branch restriction: '.$branch;
            else $passed[]='Branch matches';
        } else $passed[]='No branch restriction';
        if (isset($student['graduation_year'], $job['graduation_year']) && $job['graduation_year'] !== null) {
            if ((int)$student['graduation_year'] !== (int)$job['graduation_year']) $failed[]='Graduation year does not match'; else $passed[]='Graduation year matches';
        }
        return ['eligible'=>!$failed && !$missing, 'passed'=>$passed, 'failed'=>$failed, 'missing'=>$missing];
    }
}
