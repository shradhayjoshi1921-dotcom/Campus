<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Interview questions and evaluation rubrics from a role's real requirements.
 *
 * Questions are templates filled with the job's own skills, so an interviewer
 * gets relevant prompts without a model inventing experience the candidate does
 * not have. The rubric is descriptive: a human scores it.
 */
final class InterviewKitService
{
    /** @return array<int,array{type:string,prompt:string,skill:string,rubric:array<int,string>}> */
    public function build(array $required, array $preferred = [], string $title = ''): array
    {
        $kit = [];
        $skills = array_values(array_unique(array_merge($required, $preferred)));
        foreach (array_slice($required, 0, 4) as $skill) {
            $kit[] = [
                'type' => 'technical',
                'skill' => (string) $skill,
                'prompt' => 'Walk me through a problem you solved using ' . $skill . '. What was the constraint, what did you choose, and why?',
                'rubric' => [
                    '1 — Names the tool but cannot describe a use.',
                    '2 — Describes a task, no trade-off or outcome.',
                    '3 — Explains the approach and one trade-off with a concrete result.',
                    '4 — Explains alternatives, trade-offs and what they would change.',
                ],
            ];
            $kit[] = [
                'type' => 'technical',
                'skill' => (string) $skill,
                'prompt' => 'Here is a ' . $skill . ' design for a small feature. Where would it break first, and how would you test that?',
                'rubric' => [
                    '1 — Cannot identify a failure mode.',
                    '2 — Guesses a failure without a reason.',
                    '3 — Identifies a realistic failure and a test for it.',
                    '4 — Prioritises failure modes by impact and describes the test and the fix.',
                ],
            ];
        }
        foreach (array_slice($skills, 0, 2) as $skill) {
            $kit[] = [
                'type' => 'coding',
                'skill' => (string) $skill,
                'prompt' => 'Write a small function relevant to ' . $skill . '. Narrate your plan before coding, then describe how you would test it.',
                'rubric' => [
                    '1 — No attempt or no plan.',
                    '2 — Works partially; no tests mentioned.',
                    '3 — Correct approach, handles one edge case, names a test.',
                    '4 — Correct, handles edge cases, discusses complexity and tests.',
                ],
            ];
        }
        $kit[] = [
            'type' => 'aptitude',
            'skill' => '',
            'prompt' => 'A deployment fails only for some users. Describe how you would narrow down the cause, step by step.',
            'rubric' => [
                '1 — Random guessing.',
                '2 — One hypothesis, no method.',
                '3 — Structured narrowing (logs, scope, reproduction).',
                '4 — Structured narrowing plus how to prevent a repeat.',
            ],
        ];
        $kit[] = [
            'type' => 'behavioural',
            'skill' => '',
            'prompt' => 'Tell me about a time a deadline moved. What did you do, and what happened?',
            'rubric' => [
                '1 — No example.',
                '2 — Vague example, no personal action.',
                '3 — Clear situation, action and result.',
                '4 — Situation, action, measurable result and what they learned.',
            ],
        ];
        if ($title !== '') {
            $kit[] = [
                'type' => 'role',
                'skill' => '',
                'prompt' => 'Why this ' . $title . ' role, and what would you want to learn in the first three months?',
                'rubric' => [
                    '1 — No connection to the role.',
                    '2 — Generic interest.',
                    '3 — Connects their evidence to the role and names a learning goal.',
                    '4 — Specific, evidence-based and realistic about the learning curve.',
                ],
            ];
        }
        return $kit;
    }

    /** Aggregate the human-recorded feedback without replacing the human. */
    public function summarise(array $feedback): array
    {
        $entries = array_values(array_filter($feedback, static fn ($row): bool => trim((string) ($row['feedback'] ?? '')) !== ''));
        if ($entries === []) {
            return ['count' => 0, 'average' => null, 'summary' => 'No interviewer feedback has been recorded yet.'];
        }
        $ratings = array_values(array_filter(array_map(static fn ($row) => $row['rating'] ?? null, $entries), static fn ($value): bool => $value !== null));
        $average = $ratings === [] ? null : round(array_sum($ratings) / count($ratings), 1);
        return [
            'count' => count($entries),
            'average' => $average,
            'summary' => sprintf(
                '%d interviewer note%s recorded%s. These are individual observations, not a hiring decision.',
                count($entries),
                count($entries) === 1 ? '' : 's',
                $average === null ? '' : ' (average rating ' . $average . '/5)',
            ),
        ];
    }
}
