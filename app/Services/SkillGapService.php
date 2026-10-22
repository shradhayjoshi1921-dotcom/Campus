<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Compares a student's evidence against a role's requirements.
 *
 * A skill can be absent, claimed-but-undemonstrated, or demonstrated. Only the
 * first two are gaps, and they need different advice: add evidence for a claim,
 * start learning for an absent skill. Priority follows the employer's own
 * requirement type, refined by how much of the role depends on the skill
 * (a required skill in a short list matters more than one in a long list).
 */
final class SkillGapService
{
    public function analyze(array $owned, array $required, array $preferred = []): array
    {
        $normalise = static fn ($value): string => mb_strtolower(trim((string) $value));
        $owned = array_map($normalise, $owned);
        $required = array_map($normalise, $required);
        $preferred = array_map($normalise, $preferred);
        return [
            'mandatory_missing' => array_values(array_diff($required, $owned)),
            'preferred_missing' => array_values(array_diff($preferred, $owned)),
            'matched_required' => array_values(array_intersect($required, $owned)),
        ];
    }

    /**
     * @param array<int,string> $required
     * @param array<int,string> $preferred
     * @param array<string,array{level:string,value:float,sources?:array}> $evidence
     * @return array<int,array{skill:string,priority:string,importance:int,status:string,why:string,evidence:string}>
     */
    public function prioritise(array $required, array $preferred, array $evidence, EvidenceService $evidenceService, ScoringModel $model): array
    {
        $threshold = $model->demonstratedThreshold();
        $rows = [];
        $requiredCount = max(1, count($required));
        foreach ([['list' => $required, 'type' => 'required'], ['list' => $preferred, 'type' => 'preferred']] as $group) {
            foreach ($group['list'] as $skill) {
                $key = $evidenceService->normalise((string) $skill);
                $row = $evidence[$key] ?? null;
                $value = (float) ($row['value'] ?? 0.0);
                $level = (string) ($row['level'] ?? 'none');
                if ($value <= 0) {
                    $status = 'missing';
                } elseif ($value < $threshold) {
                    $status = 'claimed';
                } else {
                    $status = 'demonstrated';
                }
                // Importance is a 1–3 scale used for ordering only, never shown
                // as a numeric score.
                $importance = match (true) {
                    $group['type'] === 'required' && $status !== 'demonstrated' => $requiredCount <= 3 ? 3 : 2,
                    $group['type'] === 'required' => 2,
                    $status !== 'demonstrated' => 1,
                    default => 1,
                };
                if ($status === 'demonstrated') {
                    continue;
                }
                $rows[] = [
                    'skill' => (string) $skill,
                    'priority' => $importance === 3 ? 'High' : ($importance === 2 ? 'Medium' : 'Low'),
                    'importance' => $importance,
                    'status' => $status,
                    'type' => $group['type'],
                    'why' => $this->why($group['type'], $status),
                    'evidence' => $this->evidenceSummary($row),
                ];
            }
        }
        usort($rows, static fn (array $a, array $b): int => $b['importance'] <=> $a['importance'] ?: strcmp($a['skill'], $b['skill']));
        return $rows;
    }

    private function why(string $type, string $status): string
    {
        if ($status === 'claimed') {
            return 'You listed this skill but nothing in your profile demonstrates it yet.';
        }
        return $type === 'required'
            ? 'The employer lists this as required, so it is checked first.'
            : 'The employer lists this as preferred; it strengthens an application but is not required.';
    }

    private function evidenceSummary(?array $row): string
    {
        if ($row === null || ($row['sources'] ?? []) === []) {
            return 'No evidence recorded.';
        }
        return 'Evidence: ' . implode('; ', array_slice($row['sources'], 0, 3)) . '.';
    }

    /** Compare two roles' requirement lists, for the multi-target view. */
    public function compare(array $roles): array
    {
        $all = [];
        foreach ($roles as $role) {
            foreach (array_merge($role['required'] ?? [], $role['preferred'] ?? []) as $skill) {
                $key = mb_strtolower(trim((string) $skill));
                $all[$key] = ($all[$key] ?? 0) + 1;
            }
        }
        arsort($all);
        return $all;
    }
}
