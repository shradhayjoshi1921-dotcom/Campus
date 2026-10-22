<?php
declare(strict_types=1);

namespace App\Services;

/**
 * College-level analytics for TPO/Admin.
 *
 * Every method here is descriptive: it counts and averages rows that already
 * exist. Nothing forecasts a placement when the data cannot support it, and the
 * anomaly queue flags records for a human to review — it never accuses anyone.
 */
final class InstitutionService
{
    /** @param array<int,array{branch:string,readiness:?float,placed:bool}> $students */
    public function readinessMap(array $students): array
    {
        $branches = [];
        foreach ($students as $student) {
            $branch = trim((string) ($student['branch'] ?? '')) ?: 'Not recorded';
            $branches[$branch]['count'] = ($branches[$branch]['count'] ?? 0) + 1;
            if ($student['readiness'] !== null) {
                $branches[$branch]['scored'] = ($branches[$branch]['scored'] ?? 0) + 1;
                $branches[$branch]['sum'] = ($branches[$branch]['sum'] ?? 0) + (float) $student['readiness'];
            }
        }
        $rows = [];
        foreach ($branches as $branch => $data) {
            $scored = (int) ($data['scored'] ?? 0);
            $rows[] = [
                'branch' => (string) $branch,
                'students' => (int) $data['count'],
                'measured' => $scored,
                'average' => $scored > 0 ? round(((float) $data['sum']) / $scored, 1) : null,
            ];
        }
        usort($rows, static fn (array $a, array $b): int => ($b['average'] ?? -1) <=> ($a['average'] ?? -1));
        return $rows;
    }

    /**
     * @param array<int,array{skill:string,type:string,company?:string,job?:string}> $requirements
     */
    public function skillDemand(array $requirements): array
    {
        $counts = [];
        $combos = [];
        $perJob = [];
        foreach ($requirements as $row) {
            $skill = mb_strtolower(trim((string) $row['skill']));
            if ($skill === '') {
                continue;
            }
            $counts[$skill]['required'] = ($counts[$skill]['required'] ?? 0) + ($row['type'] === 'required' ? 1 : 0);
            $counts[$skill]['preferred'] = ($counts[$skill]['preferred'] ?? 0) + ($row['type'] === 'preferred' ? 1 : 0);
            $counts[$skill]['label'] = $row['skill'];
            $jobKey = (string) ($row['job'] ?? '');
            if ($jobKey !== '') {
                $perJob[$jobKey][] = $skill;
            }
        }
        foreach ($perJob as $skills) {
            $unique = array_values(array_unique($skills));
            sort($unique);
            for ($i = 0; $i < count($unique); $i++) {
                for ($j = $i + 1; $j < count($unique); $j++) {
                    $key = $unique[$i] . ' + ' . $unique[$j];
                    $combos[$key] = ($combos[$key] ?? 0) + 1;
                }
            }
        }
        $rows = [];
        foreach ($counts as $skill => $data) {
            $rows[] = [
                'skill' => (string) $data['label'],
                'required' => (int) $data['required'],
                'preferred' => (int) $data['preferred'],
                'total' => (int) $data['required'] + (int) $data['preferred'],
            ];
        }
        usort($rows, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);
        arsort($combos);
        $pairs = [];
        foreach (array_slice($combos, 0, 6, true) as $pair => $count) {
            $pairs[] = ['pair' => $pair, 'jobs' => $count];
        }
        return ['skills' => $rows, 'combinations' => $pairs, 'jobs_analysed' => count($perJob)];
    }

    /**
     * @param array<int,array{skill:string,students:int,jobs:int}> $gaps
     */
    public function trainingGaps(array $gaps): array
    {
        usort($gaps, static fn (array $a, array $b): int => $b['students'] <=> $a['students'] ?: $b['jobs'] <=> $a['jobs']);
        $out = [];
        foreach (array_slice($gaps, 0, 10) as $gap) {
            $students = (int) $gap['students'];
            $priority = $students >= 15 ? 'High' : ($students >= 5 ? 'Medium' : 'Low');
            $out[] = [
                'skill' => (string) $gap['skill'],
                'students' => $students,
                'jobs' => (int) $gap['jobs'],
                'priority' => $priority,
                'suggestion' => 'Run a ' . ($students >= 15 ? 'department-wide workshop' : 'targeted session') . ' on ' . $gap['skill']
                    . ' and follow it with a short assessment so progress is measurable.',
            ];
        }
        return $out;
    }

    /**
     * @param array<int,array{company:string,required:array<int,string>,jobs:int}> $companies
     * @param array<string,int> $studentSkillCounts normalised skill => number of students who have it
     * @param int $studentTotal
     */
    public function companyMatches(array $companies, array $studentSkillCounts, int $studentTotal): array
    {
        $rows = [];
        foreach ($companies as $company) {
            $required = array_values(array_filter(array_map(static fn ($s): string => mb_strtolower(trim((string) $s)), (array) $company['required'])));
            if ($required === [] || $studentTotal === 0) {
                continue;
            }
            $total = 0.0;
            $covered = 0;
            foreach ($required as $skill) {
                $students = (int) ($studentSkillCounts[$skill] ?? 0);
                $coverage = min(1.0, $students / $studentTotal);
                $total += $coverage;
                if ($coverage >= 0.25) {
                    $covered++;
                }
            }
            $rows[] = [
                'company' => (string) $company['company'],
                'jobs' => (int) $company['jobs'],
                'required' => $required,
                'coverage' => round($total / count($required) * 100, 1),
                'skills_covered' => $covered,
                'note' => $covered === count($required)
                    ? 'Your students already cover every requirement this employer lists.'
                    : 'Missing coverage: ' . implode(', ', array_values(array_diff($required, array_keys(array_filter($studentSkillCounts))))),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => $b['coverage'] <=> $a['coverage']);
        return $rows;
    }

    /**
     * Descriptive first. A trend line needs at least six periods, and even then
     * it is reported as an observed direction with a range, never a forecast.
     *
     * @param array<int,array{period:string,count:int}> $series
     */
    public function trend(array $series): array
    {
        $count = count($series);
        if ($count === 0) {
            return ['status' => 'empty', 'summary' => 'No applications have been recorded yet.', 'points' => [], 'direction' => null, 'range' => null];
        }
        $counts = array_map(static fn ($row): int => (int) $row['count'], $series);
        $summary = sprintf('%d periods, %d applications in total, %d at the latest point.', $count, array_sum($counts), end($counts));
        if ($count < 6) {
            return [
                'status' => 'insufficient',
                'summary' => $summary . ' Fewer than six periods: no trend is inferred and no forecast is shown.',
                'points' => $series,
                'direction' => null,
                'range' => null,
            ];
        }
        $firstHalf = array_sum(array_slice($counts, 0, intdiv($count, 2)));
        $secondHalf = array_sum(array_slice($counts, intdiv($count, 2)));
        $direction = $secondHalf > $firstHalf * 1.1 ? 'rising' : ($secondHalf < $firstHalf * 0.9 ? 'falling' : 'flat');
        $mean = array_sum($counts) / $count;
        $variance = 0.0;
        foreach ($counts as $value) {
            $variance += ($value - $mean) ** 2;
        }
        $sd = sqrt($variance / $count);
        return [
            'status' => 'descriptive',
            'summary' => $summary . ' Observed direction: ' . $direction . '.',
            'points' => $series,
            'direction' => $direction,
            'range' => [max(0, round($mean - $sd, 1)), round($mean + $sd, 1)],
            'note' => 'This is an observed range from past periods, not a forecast of future placements.',
        ];
    }

    /**
     * @param array<int,array{type:string,detail:string,record:string}> $rows
     */
    public function anomalies(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'type' => (string) $row['type'],
                'detail' => (string) $row['detail'],
                'record' => (string) $row['record'],
                'action' => 'Review the record. This is an automated flag, not a finding of wrongdoing.',
            ];
        }
        return $out;
    }
}
