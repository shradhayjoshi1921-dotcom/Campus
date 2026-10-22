<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Turns raw profile rows into an evidence level per skill.
 *
 * The rule the product depends on: adding a skill to a profile is a *claim*,
 * not a demonstration. Only a project, a certification, an assessment result or
 * staff verification raises the level. Evidence is additive — the strongest
 * source wins, and every source is kept so the UI can cite it.
 */
final class EvidenceService
{
    public function __construct(private readonly ScoringModel $model)
    {
    }

    /**
     * @param array<int, array{name:string, proficiency?:string, verified?:int|bool}> $skillRows
     * @param array<int, array{title?:string, skills_json?:string|array}>              $projects
     * @param array<int, array{title?:string, issuer?:string}>                         $certifications
     * @param array<int, array{skill:string, level:string, note?:string}>              $extra
     * @return array<string, array{level:string, value:float, sources:array<int,string>}>
     */
    public function build(array $skillRows, array $projects = [], array $certifications = [], array $extra = []): array
    {
        $map = [];

        foreach ($skillRows as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $level = !empty($row['verified']) ? 'verified' : $this->levelFromProficiency((string) ($row['proficiency'] ?? ''));
            $this->record($map, $name, $level, 'Profile entry' . (($row['proficiency'] ?? '') !== '' ? ' (' . $row['proficiency'] . ')' : ''));
        }

        foreach ($projects as $project) {
            $title = trim((string) ($project['title'] ?? 'Project'));
            $skills = $project['skills_json'] ?? [];
            if (is_string($skills)) {
                $decoded = json_decode($skills, true);
                $skills = is_array($decoded) ? $decoded : (preg_split('/[,;]+/', $skills) ?: []);
            }
            foreach ((array) $skills as $skill) {
                $skill = trim((string) $skill);
                if ($skill !== '') {
                    $this->record($map, $skill, 'project', 'Project: ' . mb_substr($title, 0, 80));
                }
            }
        }

        foreach ($certifications as $certification) {
            $title = trim((string) ($certification['title'] ?? 'Certification'));
            foreach ($this->skillsFromText($title) as $skill) {
                $this->record($map, $skill, 'certification', 'Certification: ' . mb_substr($title, 0, 80));
            }
        }

        foreach ($extra as $row) {
            $this->record($map, (string) ($row['skill'] ?? ''), (string) ($row['level'] ?? 'claimed'), (string) ($row['note'] ?? 'Assessment result'));
        }

        return $map;
    }

    /** @param array<string, mixed> $map */
    private function record(array &$map, string $skill, string $level, string $source): void
    {
        $key = $this->normalise($skill);
        if ($key === '') {
            return;
        }
        $value = $this->model->evidenceValue($level);
        if (!isset($map[$key])) {
            $map[$key] = ['level' => 'none', 'value' => 0.0, 'sources' => [], 'display' => $skill];
        }
        if ($value >= $map[$key]['value']) {
            $map[$key]['level'] = $level;
            $map[$key]['value'] = $value;
        }
        if (!in_array($source, $map[$key]['sources'], true) && count($map[$key]['sources']) < 4) {
            $map[$key]['sources'][] = $source;
        }
    }

    private function levelFromProficiency(string $proficiency): string
    {
        // Proficiency describes self-assessed depth; it never exceeds a claim.
        return match (strtolower($proficiency)) {
            'advanced', 'intermediate', 'beginner' => 'claimed',
            default => 'claimed',
        };
    }

    /** Pull known skill names out of free text such as a certification title. */
    public function skillsFromText(string $text): array
    {
        $known = ['php', 'sql', 'mysql', 'javascript', 'typescript', 'react', 'node.js', 'node', 'python', 'java', 'c++', 'git', 'docker', 'aws', 'azure', 'linux', 'html', 'css', 'laravel', 'django', 'spring', 'kotlin', 'swift', 'go', 'rust', 'mongodb', 'postgresql', 'redis', 'figma', 'excel', 'power bi', 'tableau', 'data structures', 'algorithms'];
        $lower = mb_strtolower($text);
        $found = [];
        foreach ($known as $skill) {
            if (preg_match('/(?<![a-z0-9+#.])' . preg_quote($skill, '/') . '(?![a-z0-9+#])/', $lower) === 1) {
                $found[] = $skill;
            }
        }
        return array_values(array_unique($found));
    }

    public function normalise(string $skill): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $skill) ?? ''));
    }

    /** Skills at or above the demonstrated threshold, for readiness copy. */
    public function demonstrated(array $map): array
    {
        $threshold = $this->model->demonstratedThreshold();
        return array_values(array_filter($map, static fn (array $row): bool => $row['value'] >= $threshold));
    }
}
