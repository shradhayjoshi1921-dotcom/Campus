<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Scores objective assessment questions and estimates a skill level.
 *
 * Only objectively gradable questions are scored automatically. A coding task
 * with no sandbox is not executed here — it is recorded for human review, which
 * the UI states plainly.
 */
final class AssessmentService
{
    public function __construct(private ScoringModel $model)
    {
    }

    /**
     * @param array<int,array{id:int,correct:int,points?:float,skill?:string}> $questions
     * @param array<int,int|string> $answers keyed by question id
     * @param array<int,array> $allQuestions the session's questions, for explanations
     */
    public function score(array $questions, array $answers): array
    {
        $points = 0.0;
        $earned = 0.0;
        $correct = 0;
        $review = [];
        foreach ($questions as $question) {
            $weight = (float) ($question['points'] ?? 1);
            $points += $weight;
            $given = $answers[(int) $question['id']] ?? $answers[(string) $question['id']] ?? null;
            $isCorrect = $given !== null && (int) $given === (int) $question['correct'];
            if ($isCorrect) {
                $earned += $weight;
                $correct++;
            }
            $review[] = [
                'question_id' => (int) $question['id'],
                'given' => $given === null ? null : (int) $given,
                'correct' => (int) $question['correct'],
                'is_correct' => $isCorrect,
                'skill' => (string) ($question['skill'] ?? ''),
            ];
        }
        $percent = $points > 0 ? round($earned / $points * 100, 1) : 0.0;
        return [
            'points' => $points,
            'earned' => $earned,
            'percent' => $percent,
            'correct' => $correct,
            'total' => count($questions),
            'review' => $review,
            'level' => $this->level($percent, count($questions)),
        ];
    }

    /**
     * A cautious band, not a certification. Fewer than five questions is not
     * enough evidence for anything but "not yet measured".
     */
    public function level(float $percent, int $questionCount): string
    {
        if ($questionCount < 5) {
            return 'Not enough evidence';
        }
        if ($percent >= 85) {
            return 'Advanced';
        }
        if ($percent >= 65) {
            return 'Intermediate';
        }
        if ($percent >= 40) {
            return 'Developing';
        }
        return 'Beginner';
    }

    /** Evidence a completed attempt contributes to a skill. */
    public function evidenceRows(array $attempts): array
    {
        $rows = [];
        foreach ($attempts as $attempt) {
            if ((float) ($attempt['percent'] ?? 0) < 60) {
                continue;
            }
            foreach ((array) ($attempt['skills'] ?? []) as $skill) {
                $rows[] = ['skill' => (string) $skill, 'level' => 'assessment', 'note' => 'Assessment: ' . ($attempt['title'] ?? 'attempt') . ' (' . $attempt['percent'] . '%)'];
            }
        }
        return $rows;
    }

    public function isDemonstrated(float $percent): bool
    {
        return $percent >= 60;
    }
}
