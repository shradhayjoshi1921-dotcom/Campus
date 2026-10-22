<?php
declare(strict_types=1);

namespace App\Data;

use PDO;
use Throwable;

/**
 * Writes and history for the career features: fit cache, target roles,
 * persisted roadmaps, resume review, practice sessions and assessments.
 *
 * Every method either scopes its statement to the signed-in student's id or
 * takes an id that the caller has already authorised; ownership is re-checked
 * in the WHERE clause wherever a row could belong to someone else.
 */
final class CareerStore
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * Record a fit snapshot for a role the student opened, at most once every
     * six hours, so the history shows real movement instead of page refreshes.
     */
    public function recordFit(int $studentId, int $jobId, array $fit, array $gaps = []): void
    {
        try {
            $statement = $this->db->prepare(
                'SELECT computed_at FROM career_fit_results WHERE student_id = ? AND job_id = ? ORDER BY computed_at DESC LIMIT 1',
            );
            $statement->execute([$studentId, $jobId]);
            $last = (string) ($statement->fetchColumn() ?: '');
            if ($last !== '' && strtotime($last) !== false && time() - (int) strtotime($last) < 6 * 3600) {
                return;
            }
        } catch (Throwable $exception) {
            return;
        }
        $this->saveFit($studentId, $jobId, $fit);
        if ($gaps !== []) {
            $this->saveGaps($studentId, $jobId, $gaps);
        }
    }

    /* ---------------------------------------------------------- fit cache */

    public function saveFit(int $studentId, int $jobId, array $fit): void
    {
        try {
            $this->db->prepare(
                'INSERT INTO career_fit_results (student_id, job_id, fit_score, components_json) VALUES (?, ?, ?, ?)',
            )->execute([
                $studentId,
                $jobId,
                (float) $fit['score'],
                json_encode(['components' => $fit['components'], 'weights' => $fit['weights'] ?? [], 'band' => $fit['band'] ?? ''], JSON_UNESCAPED_SLASHES),
            ]);
        } catch (Throwable $exception) {
            // The cache is history and an optimisation; never a hard failure.
        }
    }

    public function saveGaps(int $studentId, int $jobId, array $gaps): void
    {
        try {
            $this->db->prepare(
                'INSERT INTO skill_gap_results (student_id, job_id, missing_skills_json) VALUES (?, ?, ?)',
            )->execute([$studentId, $jobId, json_encode(array_values(array_map(static fn ($row) => $row['skill'], $gaps)), JSON_UNESCAPED_SLASHES)]);
        } catch (Throwable $exception) {
        }
    }

    public function fitHistory(int $studentId, int $jobId, int $limit = 12): array
    {
        try {
            $statement = $this->db->prepare(
                'SELECT fit_score, computed_at FROM career_fit_results WHERE student_id = ? AND job_id = ? ORDER BY computed_at DESC LIMIT ' . max(1, min(50, $limit)),
            );
            $statement->execute([$studentId, $jobId]);
            return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $exception) {
            return [];
        }
    }

    /* ------------------------------------------------------------ targets */

    public function targets(int $studentId): array
    {
        try {
            $statement = $this->db->prepare('SELECT job_id FROM career_targets WHERE student_id = ? ORDER BY created_at DESC');
            $statement->execute([$studentId]);
            return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN) ?: []);
        } catch (Throwable $exception) {
            return [];
        }
    }

    public function toggleTarget(int $studentId, int $jobId): bool
    {
        $existing = in_array($jobId, $this->targets($studentId), true);
        try {
            if ($existing) {
                $this->db->prepare('DELETE FROM career_targets WHERE student_id = ? AND job_id = ?')->execute([$studentId, $jobId]);
                return false;
            }
            $this->db->prepare('INSERT INTO career_targets (student_id, job_id) VALUES (?, ?)')->execute([$studentId, $jobId]);
            return true;
        } catch (Throwable $exception) {
            return $existing;
        }
    }

    /* ------------------------------------------------------------ roadmaps */

    public function activeRoadmap(int $studentId): ?array
    {
        try {
            $statement = $this->db->prepare('SELECT * FROM learning_roadmaps WHERE student_id = ? ORDER BY created_at DESC LIMIT 1');
            $statement->execute([$studentId]);
            $roadmap = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$roadmap) {
                return null;
            }
            $items = $this->db->prepare('SELECT * FROM roadmap_items WHERE roadmap_id = ? ORDER BY week_no, sort_order, id');
            $items->execute([(int) $roadmap['id']]);
            $roadmap['items'] = $items->fetchAll(PDO::FETCH_ASSOC) ?: [];
            return $roadmap;
        } catch (Throwable $exception) {
            return null;
        }
    }

    /** Replace the active plan. Student-initiated only. */
    public function saveRoadmap(int $studentId, ?int $jobId, string $title, array $plan): int
    {
        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM learning_roadmaps WHERE student_id = ?')->execute([$studentId]);
            $this->db->prepare('INSERT INTO learning_roadmaps (student_id, job_id, title) VALUES (?, ?, ?)')
                ->execute([$studentId, $jobId ?: null, mb_substr($title, 0, 180)]);
            $roadmapId = (int) $this->db->lastInsertId();
            $insert = $this->db->prepare(
                'INSERT INTO roadmap_items (roadmap_id, skill, priority, practice_task, project_idea, estimated_hours, status, sort_order, week_no, task_type, title, resource) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            );
            $order = 0;
            foreach ($plan as $week) {
                foreach ($week['tasks'] as $task) {
                    $insert->execute([
                        $roadmapId,
                        mb_substr((string) ($task['skill'] ?? ''), 0, 100),
                        'Medium',
                        mb_substr((string) ($task['detail'] ?? ''), 0, 2000),
                        '',
                        max(0, (int) ($task['hours'] ?? 0)),
                        'not_started',
                        $order++,
                        (int) $week['week'],
                        mb_substr((string) ($task['type'] ?? 'technical'), 0, 30),
                        mb_substr((string) ($task['title'] ?? ''), 0, 180),
                        null,
                    ]);
                }
            }
            $this->db->commit();
            return $roadmapId;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    /** Ownership is enforced in the WHERE clause, not in the caller. */
    public function setTaskStatus(int $studentId, int $itemId, string $status): bool
    {
        if (!in_array($status, ['not_started', 'in_progress', 'completed'], true)) {
            return false;
        }
        try {
            $statement = $this->db->prepare(
                'UPDATE roadmap_items ri JOIN learning_roadmaps lr ON lr.id = ri.roadmap_id '
                . 'SET ri.status = ? WHERE ri.id = ? AND lr.student_id = ?',
            );
            $statement->execute([$status, $itemId, $studentId]);
            return $statement->rowCount() > 0;
        } catch (Throwable $exception) {
            return false;
        }
    }

    public function roadmapProgress(array $roadmap): array
    {
        $items = $roadmap['items'] ?? [];
        $done = count(array_filter($items, static fn (array $item): bool => ($item['status'] ?? '') === 'completed'));
        $remaining = array_values(array_filter($items, static fn (array $item): bool => ($item['status'] ?? '') !== 'completed'));
        return [
            'total' => count($items),
            'done' => $done,
            'percent' => $items === [] ? 0.0 : round($done / count($items) * 100, 1),
            'next' => $remaining[0] ?? null,
        ];
    }

    /* ------------------------------------------------------- resume analysis */

    public function saveResumeAnalysis(int $resumeId, int $studentId, string $text, array $extracted, array $review, bool $ok, string $reason = ''): void
    {
        try {
            $this->db->prepare(
                'INSERT INTO resume_analyses (resume_id, student_id, extracted_text, extracted_json, review_json, status, failure_reason, extracted_at) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ' . ($ok ? 'NOW()' : 'NULL') . ') '
                . 'ON DUPLICATE KEY UPDATE extracted_text = VALUES(extracted_text), extracted_json = VALUES(extracted_json), '
                . 'review_json = VALUES(review_json), status = VALUES(status), failure_reason = VALUES(failure_reason), '
                . 'extracted_at = VALUES(extracted_at), approved_json = NULL, approved_at = NULL',
            )->execute([
                $resumeId,
                $studentId,
                $ok ? $text : null,
                $ok ? json_encode($extracted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                json_encode($review, JSON_UNESCAPED_SLASHES) ?: null,
                $ok ? 'pending' : 'failed',
                $ok ? null : mb_substr($reason, 0, 255),
            ]);
        } catch (Throwable $exception) {
            error_log('Resume analysis save failed: ' . $exception->getMessage());
        }
    }

    /**
     * Applies the fields the student ticked. Skills become claims plus a resume
     * evidence row; nothing is marked verified by this path.
     */
    public function approveResumeFields(int $studentId, int $resumeId, array $approved): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->prepare('UPDATE resume_analyses SET status = ?, approved_json = ?, approved_at = NOW() WHERE resume_id = ? AND student_id = ?')
                ->execute(['approved', json_encode($approved, JSON_UNESCAPED_SLASHES), $resumeId, $studentId]);

            foreach ((array) ($approved['skills'] ?? []) as $skill) {
                $name = trim((string) $skill);
                if ($name === '' || mb_strlen($name) > 100) {
                    continue;
                }
                $this->db->prepare('INSERT INTO skills (name) VALUES (?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)')->execute([$name]);
                $skillId = (int) $this->db->lastInsertId();
                $this->db->prepare('INSERT IGNORE INTO student_skills (student_id, skill_id, proficiency) VALUES (?, ?, ?)')->execute([$studentId, $skillId, 'beginner']);
                try {
                    $this->db->prepare(
                        'INSERT IGNORE INTO skill_evidence (student_id, skill_name, source_type, source_ref, level, note) VALUES (?, ?, ?, ?, ?, ?)',
                    )->execute([$studentId, $name, 'resume', (string) $resumeId, 'claimed', 'Listed in the approved resume']);
                } catch (Throwable $exception) {
                    // Evidence table is optional on older installs.
                }
            }
            foreach ((array) ($approved['projects'] ?? []) as $project) {
                $title = trim((string) (is_array($project) ? ($project['title'] ?? '') : $project));
                if ($title === '') {
                    continue;
                }
                $this->db->prepare('INSERT INTO student_projects (student_id, title, description) VALUES (?, ?, ?)')
                    ->execute([$studentId, mb_substr($title, 0, 180), 'Added from the approved resume review.']);
            }
            foreach ((array) ($approved['certifications'] ?? []) as $certification) {
                $title = trim((string) (is_array($certification) ? ($certification['title'] ?? '') : $certification));
                if ($title === '') {
                    continue;
                }
                $this->db->prepare('INSERT INTO student_certifications (student_id, title) VALUES (?, ?)')
                    ->execute([$studentId, mb_substr($title, 0, 180)]);
            }
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    /* ------------------------------------------------------------- practice */

    public function createPracticeSession(int $studentId, ?int $jobId, string $roleTitle, array $questions): int
    {
        $this->db->prepare('INSERT INTO practice_sessions (student_id, job_id, role_title, questions_json) VALUES (?, ?, ?, ?)')
            ->execute([$studentId, $jobId ?: null, mb_substr($roleTitle, 0, 180), json_encode($questions, JSON_UNESCAPED_SLASHES)]);
        return (int) $this->db->lastInsertId();
    }

    public function practiceSession(int $studentId, int $sessionId): ?array
    {
        try {
            $statement = $this->db->prepare('SELECT * FROM practice_sessions WHERE id = ? AND student_id = ? LIMIT 1');
            $statement->execute([$sessionId, $studentId]);
            $session = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$session) {
                return null;
            }
            $session['questions_json'] = json_decode((string) $session['questions_json'], true) ?: [];
            $answers = $this->db->prepare('SELECT * FROM practice_answers WHERE session_id = ? ORDER BY question_index');
            $answers->execute([$sessionId]);
            $session['answers'] = $answers->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($session['answers'] as &$answer) {
                $decoded = json_decode((string) ($answer['feedback_json'] ?? ''), true);
                $answer['feedback'] = is_array($decoded) ? $decoded : [];
            }
            unset($answer);
            return $session;
        } catch (Throwable $exception) {
            return null;
        }
    }

    public function savePracticeAnswer(int $sessionId, int $index, array $question, string $answer, array $feedback): void
    {
        $this->db->prepare(
            'INSERT INTO practice_answers (session_id, question_index, question_type, prompt, skill, answer, score, feedback_json) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE answer = VALUES(answer), score = VALUES(score), feedback_json = VALUES(feedback_json)',
        )->execute([
            $sessionId,
            $index,
            mb_substr((string) ($question['type'] ?? 'technical'), 0, 30),
            (string) ($question['prompt'] ?? ''),
            mb_substr((string) ($question['skill'] ?? ''), 0, 100),
            $answer,
            max(0, min(100, (int) ($feedback['score'] ?? 0))),
            json_encode($feedback, JSON_UNESCAPED_SLASHES),
        ]);
    }

    public function completePracticeSession(int $studentId, int $sessionId): void
    {
        $statement = $this->db->prepare('SELECT AVG(score) FROM practice_answers WHERE session_id = ?');
        $statement->execute([$sessionId]);
        $average = (float) ($statement->fetchColumn() ?: 0);
        $this->db->prepare('UPDATE practice_sessions SET status = ?, average_score = ?, completed_at = NOW() WHERE id = ? AND student_id = ?')
            ->execute(['completed', round($average, 2), $sessionId, $studentId]);
    }

    public function practiceHistory(int $studentId, int $limit = 10): array
    {
        try {
            $statement = $this->db->prepare(
                'SELECT id, role_title, status, average_score, created_at, completed_at FROM practice_sessions WHERE student_id = ? ORDER BY created_at DESC LIMIT ' . max(1, min(30, $limit)),
            );
            $statement->execute([$studentId]);
            return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $exception) {
            return [];
        }
    }

    /* ---------------------------------------------------------- assessments */

    public function assessments(): array
    {
        try {
            $statement = $this->db->query(
                "SELECT a.id, a.title, a.description, COUNT(q.id) AS question_count "
                . "FROM assessments a LEFT JOIN assessment_questions q ON q.assessment_id = a.id "
                . "WHERE a.status = 'published' GROUP BY a.id, a.title, a.description ORDER BY a.title",
            );
            return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $exception) {
            return [];
        }
    }

    public function assessment(int $assessmentId): ?array
    {
        try {
            $statement = $this->db->prepare("SELECT * FROM assessments WHERE id = ? AND status = 'published' LIMIT 1");
            $statement->execute([$assessmentId]);
            $assessment = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$assessment) {
                return null;
            }
            try {
                $questions = $this->db->prepare('SELECT id, question, options_json, correct_answer, points, skill FROM assessment_questions WHERE assessment_id = ? ORDER BY id');
                $questions->execute([$assessmentId]);
                $rows = $questions->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $exception) {
                // Install that has not run the assessment-skill migration yet.
                $questions = $this->db->prepare("SELECT id, question, options_json, correct_answer, points, '' AS skill FROM assessment_questions WHERE assessment_id = ? ORDER BY id");
                $questions->execute([$assessmentId]);
                $rows = $questions->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
            $assessment['questions'] = $rows;
            foreach ($assessment['questions'] as &$question) {
                $decoded = json_decode((string) ($question['options_json'] ?? ''), true);
                $question['options'] = is_array($decoded) ? array_values($decoded) : [];
                // `correct_answer` may hold the option text or its index; both
                // are accepted and anything else is left unscorable rather
                // than guessed.
                $correct = $question['correct_answer'];
                $question['correct_index'] = null;
                if ($question['options'] !== [] && $correct !== null && $correct !== '') {
                    if (preg_match('/^\d+$/', (string) $correct) === 1 && (int) $correct < count($question['options'])) {
                        $question['correct_index'] = (int) $correct;
                    } else {
                        $found = array_search((string) $correct, array_map('strval', $question['options']), true);
                        $question['correct_index'] = $found === false ? null : (int) $found;
                    }
                }
                unset($question['correct_answer']);
            }
            unset($question);
            return $assessment;
        } catch (Throwable $exception) {
            return null;
        }
    }

    public function startAttempt(int $assessmentId, int $studentId): int
    {
        $this->db->prepare('INSERT INTO assessment_attempts (assessment_id, student_id, started_at) VALUES (?, ?, NOW())')
            ->execute([$assessmentId, $studentId]);
        return (int) $this->db->lastInsertId();
    }

    public function submitAttempt(int $attemptId, int $studentId, array $scored, array $questionPoints): void
    {
        $this->db->beginTransaction();
        try {
            $owns = $this->db->prepare('SELECT id FROM assessment_attempts WHERE id = ? AND student_id = ? AND submitted_at IS NULL FOR UPDATE');
            $owns->execute([$attemptId, $studentId]);
            if ($owns->fetchColumn() === false) {
                throw new RuntimeException('That attempt is not open.');
            }
            $insert = $this->db->prepare(
                'INSERT INTO assessment_answers (attempt_id, question_id, answer, is_correct, points_awarded) VALUES (?, ?, ?, ?, ?) '
                . 'ON DUPLICATE KEY UPDATE answer = VALUES(answer), is_correct = VALUES(is_correct), points_awarded = VALUES(points_awarded)',
            );
            foreach ($scored['review'] as $row) {
                $insert->execute([
                    $attemptId,
                    $row['question_id'],
                    $row['given'] === null ? null : (string) $row['given'],
                    $row['is_correct'] ? 1 : 0,
                    $row['is_correct'] ? (float) ($questionPoints[$row['question_id']] ?? 1) : 0,
                ]);
            }
            $this->db->prepare('UPDATE assessment_attempts SET score = ?, submitted_at = NOW() WHERE id = ?')
                ->execute([(float) $scored['percent'], $attemptId]);
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }
}
