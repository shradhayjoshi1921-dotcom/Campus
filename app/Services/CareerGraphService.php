<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Builds the career graph: the student's current position, the target role, and
 * the real requirements that connect them.
 *
 * Nodes and edges only come from stored data (the job's own required and
 * preferred skills, the student's evidence, the student's projects) plus the
 * study resources the roadmap names. Nothing implies a guaranteed path.
 */
final class CareerGraphService
{
    public function __construct(private readonly RoadmapService $roadmaps)
    {
    }

    /**
     * @param array $job            the target role row (with required_skills / preferred_skills)
     * @param array $evidence       EvidenceService map
     * @param array $projects       student projects
     * @param array $certifications student certifications
     */
    public function build(array $job, array $evidence, array $projects = [], array $certifications = []): array
    {
        $nodes = [];
        $edges = [];
        $add = static function (string $id, string $label, string $kind, array $meta = []) use (&$nodes): void {
            $nodes[$id] = ['id' => $id, 'label' => $label, 'kind' => $kind] + $meta;
        };

        $add('role', (string) ($job['title'] ?? 'Target role'), 'role', ['detail' => (string) ($job['company_name'] ?? '')]);
        $add('you', 'Your profile', 'you', ['detail' => 'Skills, projects and certifications on record']);

        $required = $job['required_skills'] ?? [];
        $preferred = $job['preferred_skills'] ?? [];
        foreach ($required as $skill) {
            $key = 'skill-' . md5(mb_strtolower((string) $skill));
            $row = $evidence[mb_strtolower(trim((string) $skill))] ?? null;
            $status = $row === null ? 'missing' : (((float) $row['value']) >= 0.6 ? 'demonstrated' : 'claimed');
            $add($key, (string) $skill, 'skill', ['requirement' => 'required', 'status' => $status, 'detail' => $this->skillDetail((string) $skill, $status, $row)]);
            $edges[] = ['from' => $key, 'to' => 'role', 'kind' => 'required'];
            if ($status !== 'missing') {
                $edges[] = ['from' => 'you', 'to' => $key, 'kind' => 'has'];
            }
        }
        foreach ($preferred as $skill) {
            $key = 'skill-' . md5(mb_strtolower((string) $skill));
            $row = $evidence[mb_strtolower(trim((string) $skill))] ?? null;
            $status = $row === null ? 'missing' : (((float) $row['value']) >= 0.6 ? 'demonstrated' : 'claimed');
            $add($key, (string) $skill, 'skill', ['requirement' => 'preferred', 'status' => $status, 'detail' => $this->skillDetail((string) $skill, $status, $row)]);
            $edges[] = ['from' => $key, 'to' => 'role', 'kind' => 'preferred'];
        }

        foreach ($projects as $index => $project) {
            $id = 'project-' . $index;
            $add($id, (string) ($project['title'] ?? 'Project'), 'project', ['detail' => 'Your project']);
            $edges[] = ['from' => 'you', 'to' => $id, 'kind' => 'evidence'];
            $skills = $project['skills_json'] ?? [];
            if (is_string($skills)) {
                $decoded = json_decode($skills, true);
                $skills = is_array($decoded) ? $decoded : (preg_split('/[,;]+/', $skills) ?: []);
            }
            foreach ((array) $skills as $skill) {
                $key = 'skill-' . md5(mb_strtolower(trim((string) $skill)));
                if (isset($nodes[$key])) {
                    $edges[] = ['from' => $id, 'to' => $key, 'kind' => 'demonstrates'];
                }
            }
        }

        foreach ($certifications as $index => $certification) {
            $id = 'cert-' . $index;
            $add($id, (string) ($certification['title'] ?? 'Certification'), 'certification', ['detail' => (string) ($certification['issuer'] ?? '')]);
            $edges[] = ['from' => 'you', 'to' => $id, 'kind' => 'evidence'];
        }

        foreach ($required as $skill) {
            $resource = $this->roadmaps->resourceFor((string) $skill);
            if ($resource !== '') {
                $id = 'resource-' . md5(mb_strtolower((string) $skill));
                $add($id, $resource, 'resource', ['detail' => 'Study resource for ' . $skill]);
                $edges[] = ['from' => 'skill-' . md5(mb_strtolower((string) $skill)), 'to' => $id, 'kind' => 'learn'];
            }
        }

        return [
            'nodes' => array_values($nodes),
            'edges' => $edges,
            'notice' => 'Built from this role\'s stored requirements and your recorded evidence. A path is a suggestion, not a guarantee.',
        ];
    }

    private function skillDetail(string $skill, string $status, ?array $row): string
    {
        if ($status === 'missing') {
            return $skill . ' is required and no evidence is recorded yet.';
        }
        if ($status === 'claimed') {
            return $skill . ' is on your profile but not demonstrated by a project, certification or assessment.';
        }
        $sources = $row['sources'] ?? [];
        return $skill . ' is demonstrated' . ($sources ? ' — ' . implode('; ', array_slice($sources, 0, 2)) : '') . '.';
    }
}
