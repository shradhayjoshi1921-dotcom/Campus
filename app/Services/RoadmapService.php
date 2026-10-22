<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Deterministic preparation plans.
 *
 * `build()` keeps the original per-skill advice. `plan()` lays a full schedule
 * over it: weekly milestones that mix technical work, practice, portfolio and
 * interview preparation, weighted by the gaps the student actually has and the
 * hours they say they can study.
 *
 * Nothing here promises a placement, and a plan is only ever a suggestion the
 * student edits — completion is recorded by the student, not inferred.
 */
final class RoadmapService
{
    private const TASKS = [
        'sql' => ['priority' => 'High', 'practice' => 'Design a normalized schema and write 15 JOIN/GROUP BY queries.', 'project' => 'Build a placement reporting database.'],
        'php' => ['priority' => 'High', 'practice' => 'Build validated CRUD endpoints using PDO and CSRF protection.', 'project' => 'Create a secure job application tracker.'],
        'javascript' => ['priority' => 'Medium', 'practice' => 'Build accessible form validation and Fetch API requests.', 'project' => 'Create an interactive job search page.'],
        'react' => ['priority' => 'Medium', 'practice' => 'Practice components, props, state, and API consumption.', 'project' => 'Build a job listing UI.'],
        'python' => ['priority' => 'Medium', 'practice' => 'Solve data transformation and scripting exercises.', 'project' => 'Create a CSV career report generator.'],
        'java' => ['priority' => 'Medium', 'practice' => 'Practise collections, streams and exception handling.', 'project' => 'Build a small REST service with JUnit tests.'],
        'git' => ['priority' => 'High', 'practice' => 'Practise branching, rebasing and resolving conflicts.', 'project' => 'Publish a repository with a clean commit history.'],
        'docker' => ['priority' => 'Medium', 'practice' => 'Containerise a small app and write a compose file.', 'project' => 'Ship a reproducible local stack for one of your projects.'],
        'dsa' => ['priority' => 'High', 'practice' => 'Solve two array/string problems and one graph problem per session.', 'project' => 'Write a short note explaining each solution you solved.'],
    ];

    private const RESOURCES = [
        'php' => 'PHP manual: PDO prepared statements and sessions.',
        'sql' => 'Use a sample schema and practise EXPLAIN on each query.',
        'javascript' => 'MDN: Fetch API, form validation and accessibility.',
        'react' => 'React documentation: state, effects and lists.',
        'python' => 'Python tutorial: files, CSV and comprehensions.',
        'java' => 'Java collections and streams tutorials.',
        'git' => 'Pro Git chapters 2–3.',
        'docker' => 'Docker get-started guide.',
        'dsa' => 'Any structured problem set; keep a written solution log.',
    ];

    /** Per-skill advice, unchanged in shape for existing callers. */
    public function build(array $missingSkills): array
    {
        $items = [];
        foreach ($missingSkills as $skill) {
            $key = strtolower(trim((string) $skill));
            $items[] = ['skill' => $skill] + (self::TASKS[$key] ?? [
                'priority' => 'Medium',
                'practice' => 'Learn the fundamentals, then complete a small verified exercise.',
                'project' => 'Build a focused project demonstrating ' . $skill . '.',
            ]) + ['resource' => self::RESOURCES[$key] ?? ''];
        }
        return $items;
    }

    /**
     * A week-by-week plan for a set of gaps.
     *
     * @param array<int,string> $missingSkills ordered by importance
     * @param array{weeks?:int,hours_per_week?:int,target?:string} $options
     * @return array<int,array{week:int,theme:string,tasks:array<int,array{title:string,detail:string,type:string,hours:int,skill:string}>}>
     */
    public function plan(array $missingSkills, array $options = []): array
    {
        $weeks = max(2, min(16, (int) ($options['weeks'] ?? 6)));
        $hours = max(2, min(40, (int) ($options['hours_per_week'] ?? 8)));
        $target = trim((string) ($options['target'] ?? ''));
        $skills = array_values(array_unique(array_filter(array_map(static fn ($s): string => trim((string) $s), $missingSkills))));
        $plan = [];

        $technicalHours = max(2, (int) round($hours * 0.45));
        $practiceHours = max(1, (int) round($hours * 0.25));
        $interviewHours = max(1, $hours - $technicalHours - $practiceHours);

        for ($week = 1; $week <= $weeks; $week++) {
            $skill = $skills[($week - 1) % max(1, count($skills))] ?? '';
            $advice = $skill !== '' ? $this->build([$skill])[0] : null;
            $isFinal = $week === $weeks;
            $isFirst = $week === 1;

            $tasks = [];
            $tasks[] = [
                'title' => $isFirst ? 'Set up a practice log and check your profile' : ($skill !== '' ? 'Study ' . $skill . ' fundamentals' : 'Revise the gap flagged last week'),
                'detail' => $isFirst
                    ? 'Complete every section of your Campus profile, add your resume and list the skills you already use honestly.'
                    : ($advice['practice'] ?? 'Work through one focused exercise and write down what you learned.'),
                'type' => 'technical',
                'hours' => $technicalHours,
                'skill' => $skill,
            ];
            $tasks[] = [
                'title' => $isFirst ? 'Two practice problems, written up' : 'Practice set with a written solution log',
                'detail' => 'Solve problems without looking at the answer first, then write one paragraph on what you would do differently.',
                'type' => 'practice',
                'hours' => $practiceHours,
                'skill' => $skill,
            ];
            $tasks[] = [
                'title' => $isFinal ? 'Mock interview and feedback review' : 'Interview questions on this week\'s skill',
                'detail' => $isFinal
                    ? 'Run a mock interview session in Campus, read the feedback and fix the weakest answer.'
                    : 'Answer three questions aloud, then improve each answer using the feedback rubric.',
                'type' => 'interview',
                'hours' => $interviewHours,
                'skill' => $skill,
            ];
            if ($skill !== '' && ($advice['project'] ?? '') !== '') {
                $tasks[] = [
                    'title' => 'Portfolio: ' . $advice['project'],
                    'detail' => 'Keep it in version control, write a README with the problem and the trade-offs, and link it from your profile.',
                    'type' => 'portfolio',
                    'hours' => max(1, (int) round($hours * 0.2)),
                    'skill' => $skill,
                ];
            }
            if ($isFinal) {
                $tasks[] = [
                    'title' => 'Tailor your resume to ' . ($target !== '' ? $target : 'your target role'),
                    'detail' => 'Rewrite three bullets to name the skill, the action and the measurable result — only for work you actually did.',
                    'type' => 'resume',
                    'hours' => max(1, (int) round($hours * 0.15)),
                    'skill' => '',
                ];
            }

            $plan[] = [
                'week' => $week,
                'theme' => $isFirst ? 'Profile and baseline' : ($isFinal ? 'Interview ready' : 'Build ' . ($skill !== '' ? $skill : 'core skills')),
                'tasks' => $tasks,
            ];
        }
        return $plan;
    }

    public function resourceFor(string $skill): string
    {
        return self::RESOURCES[strtolower(trim($skill))] ?? '';
    }
}
