<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Text-based mock interviews.
 *
 * Questions come from the target role's real requirements. Feedback is a
 * transparent rubric over what the student wrote — structure, specificity,
 * mention of the relevant skill, and a concrete result. It measures the *answer*,
 * not the person: no claim is made about confidence, personality, emotion or
 * hiring potential, and there is no voice or face analysis anywhere.
 */
final class MockInterviewService
{
    public function __construct(private InterviewKitService $kit)
    {
    }

    /** @return array<int,array{type:string,prompt:string,skill:string,rubric:array}> */
    public function questions(array $required, array $preferred = [], string $title = ''): array
    {
        $questions = $this->kit->build($required, $preferred, $title);
        // Keep a session short enough to finish: one technical, one coding, one behavioural.
        $picked = [];
        foreach (['technical', 'coding', 'behavioural', 'role'] as $type) {
            foreach ($questions as $question) {
                if ($question['type'] === $type && count($picked) < 4) {
                    $picked[] = $question;
                    break;
                }
            }
        }
        return $picked ?: array_slice($questions, 0, 4);
    }

    /**
     * @return array{score:int,criteria:array<string,array{met:bool,note:string}>,feedback:string,improved:string,next:string}
     */
    public function evaluate(string $answer, array $question): array
    {
        $answer = trim($answer);
        $words = preg_split('/\s+/', $answer) ?: [];
        $length = count(array_filter($words, static fn (string $word): bool => $word !== ''));
        $lower = mb_strtolower($answer);
        $skill = mb_strtolower((string) ($question['skill'] ?? ''));

        $structureWords = ['first', 'then', 'because', 'so that', 'however', 'finally', 'result', 'instead'];
        $structureHits = count(array_filter($structureWords, static fn (string $word): bool => str_contains($lower, $word)));
        $hasStructure = $structureHits >= 2 || $length >= 70;
        $hasSpecifics = preg_match('/\b\d+\b/', $answer) === 1;
        $mentionsSkill = $skill === '' || str_contains($lower, $skill);
        $hasResult = (bool) preg_match('/\b(result|so|therefore|reduced|improved|shipped|delivered|learned|outcome)\b/i', $answer);

        $criteria = [
            'completeness' => ['met' => $length >= 40, 'note' => $length >= 40 ? 'The answer has enough detail to judge.' : 'The answer is very short; give the situation, the action and the result.'],
            'structure' => ['met' => $hasStructure, 'note' => $hasStructure ? 'The answer has a visible structure.' : 'Use situation → action → result, with signposting words.'],
            'specificity' => ['met' => $hasSpecifics, 'note' => $hasSpecifics ? 'A concrete figure or detail appears.' : 'Add a verifiable detail (a number, a size, a duration) if you have one.'],
            'relevance' => ['met' => $mentionsSkill, 'note' => $mentionsSkill ? 'The answer stays on the question\'s skill.' : 'Name the skill the question asks about and connect your example to it.'],
            'outcome' => ['met' => $hasResult, 'note' => $hasResult ? 'You stated an outcome.' : 'Say what changed as a result.'],
        ];
        $met = count(array_filter($criteria, static fn (array $criterion): bool => $criterion['met']));
        $score = (int) round($met / count($criteria) * 100);

        $improved = $this->improve($answer, $question, $criteria, $hasSpecifics);
        return [
            'score' => $score,
            'criteria' => $criteria,
            'feedback' => $met === count($criteria)
                ? 'This answer covers the rubric. Read it aloud once to check it stays under two minutes.'
                : 'Guidance, not a measurement of you: ' . implode(' ', array_values(array_map(
                    static fn (string $key): string => $criteria[$key]['note'],
                    array_keys(array_filter($criteria, static fn (array $criterion): bool => !$criterion['met'])),
                ))),
            'improved' => $improved,
            'next' => $question['type'] === 'behavioural'
                ? 'Next: answer the same question again, keeping it under 90 seconds.'
                : 'Next: try the follow-up question and name the trade-off explicitly.',
        ];
    }

    private function improve(string $answer, array $question, array $criteria, bool $hasSpecifics): string
    {
        $skill = (string) ($question['skill'] ?? '');
        $opening = trim(strtok($answer, '.') ?: '');
        $opening = $opening === '' ? 'Situation: describe the context in one sentence.' : 'Situation: ' . $opening . '.';
        $action = 'Action: what you personally did' . ($skill !== '' ? ' with ' . $skill : '') . ', and the trade-off you chose.';
        $result = $hasSpecifics
            ? 'Result: keep the figure you gave and say what it changed.'
            : 'Result: state the outcome; add a real figure only if you can verify it.';
        $missing = array_keys(array_filter($criteria, static fn (array $criterion): bool => !$criterion['met']));
        return implode("\n", array_filter([
            $opening,
            $action,
            $result,
            in_array('structure', $missing, true) ? 'Structure: use "first / then / so that" so the interviewer can follow.' : '',
        ]));
    }
}
