<?php
declare(strict_types=1);

namespace App\Data;

use App\Services\EvidenceService;
use App\Services\ScoringModel;
use PDO;
use Throwable;

/**
 * Every database read behind the student career features.
 *
 * Two rules hold throughout: every statement is prepared, and every
 * student-scoped statement filters on the signed-in student's own id. A missing
 * optional table (an install that has not migrated yet) degrades to an empty
 * result instead of a fatal error, so the rest of the workspace keeps working.
 */
final class StudentData
{
    public function __construct(
        private PDO $db,
        private ScoringModel $model,
        private EvidenceService $evidence,
    ) {
    }

    /**
     * Site-wide overrides (scoring weights, AI toggles). Read-only here: only
     * the admin workspace may write system_settings.
     *
     * @return array<string,string>
     */
    public function settings(): array
    {
        try {
            $rows = $this->db->query('SELECT setting_key, setting_value FROM system_settings')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $exception) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['setting_key']] = (string) $row['setting_value'];
        }
        return $out;
    }

    public function unreadNotifications(int $studentId): int
    {
        try {
            $statement = $this->db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
            $statement->execute([$studentId]);
            return (int) $statement->fetchColumn();
        } catch (Throwable $exception) {
            return 0;
        }
    }

    public function profile(int $studentId): array
    {
        try {
            $statement = $this->db->prepare('SELECT * FROM student_profiles WHERE user_id = ? LIMIT 1');
            $statement->execute([$studentId]);
            return $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $exception) {
            return [];
        }
    }

    /** @return array<int,array{name:string,proficiency:string,verified:int}> */
    public function skills(int $studentId): array
    {
        try {
            $statement = $this->db->prepare(
                'SELECT s.name, ss.proficiency, ss.verified FROM student_skills ss '
                . 'JOIN skills s ON s.id = ss.skill_id WHERE ss.student_id = ? ORDER BY s.name',
            );
            $statement->execute([$studentId]);
            return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $exception) {
            return [];
        }
    }

    public function projects(int $studentId): array
    {
        try {
            $statement = $this->db->prepare('SELECT id, title, description, project_url, skills_json FROM student_projects WHERE student_id = ? ORDER BY created_at DESC LIMIT 20');
            $statement->execute([$studentId]);
            return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $exception) {
            return [];
        }
    }

    public function certifications(int $studentId): array
    {
        try {
            $statement = $this->db->prepare('SELECT id, title, issuer, issued_on, credential_url FROM student_certifications WHERE student_id = ? ORDER BY COALESCE(issued_on, created_at) DESC LIMIT 20');
            $statement->execute([$studentId]);
            return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $exception) {
            return [];
        }
    }

    public function resume(int $studentId): ?array
    {
        try {
            $statement = $this->db->prepare('SELECT * FROM resumes WHERE student_id = ? ORDER BY created_at DESC LIMIT 1');
            $statement->execute([$studentId]);
            return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $exception) {
            return null;
        }
    }

    public function resumeAnalysis(int $studentId): ?array
    {
        try {
            $statement = $this->db->prepare(
                'SELECT ra.*, r.original_name FROM resume_analyses ra JOIN resumes r ON r.id = ra.resume_id '
                . 'WHERE ra.student_id = ? ORDER BY ra.id DESC LIMIT 1',
            );
            $statement->execute([$studentId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }
            foreach (['extracted_json', 'approved_json', 'review_json'] as $key) {
                $decoded = json_decode((string) ($row[$key] ?? ''), true);
                $row[$key] = is_array($decoded) ? $decoded : [];
            }
            return $row;
        } catch (Throwable $exception) {
            return null;
        }
    }

    /** @return array<string,array{level:string,value:float,sources:array<int,string>}> */
    public function evidence(int $studentId): array
    {
        $extra = $this->assessmentEvidence($studentId);
        try {
            $statement = $this->db->prepare('SELECT skill_name, level, note FROM skill_evidence WHERE student_id = ?');
            $statement->execute([$studentId]);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $extra[] = ['skill' => $row['skill_name'], 'level' => $row['level'], 'note' => (string) ($row['note'] ?? 'Recorded evidence')];
            }
        } catch (Throwable $exception) {
            // No skill_evidence table yet: profile evidence still applies.
        }
        return $this->evidence->build(
            $this->skills($studentId),
            $this->projects($studentId),
            $this->certifications($studentId),
            $extra,
        );
    }

    private function assessmentEvidence(int $studentId): array
    {
        try {
            $statement = $this->db->prepare(
                'SELECT a.id, a.title, at.score FROM assessment_attempts at JOIN assessments a ON a.id = at.assessment_id '
                . 'WHERE at.student_id = ? AND at.submitted_at IS NOT NULL AND at.score >= 60 ORDER BY at.submitted_at DESC LIMIT 10',
            );
            $statement->execute([$studentId]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $exception) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            try {
                $skillStatement = $this->db->prepare(
                    'SELECT DISTINCT q.skill FROM assessment_questions q JOIN assessment_answers an ON an.question_id = q.id '
                    . 'WHERE an.attempt_id IN (SELECT id FROM assessment_attempts WHERE student_id = ? AND assessment_id = ?) '
                    . 'AND an.is_correct = 1 AND q.skill IS NOT NULL',
                );
                $skillStatement->execute([$studentId, (int) $row['id']]);
                $skills = $skillStatement->fetchAll(PDO::FETCH_COLUMN) ?: [];
            } catch (Throwable $exception) {
                $skills = [];
            }
            foreach ($skills as $skill) {
                $out[] = ['skill' => (string) $skill, 'level' => 'assessment', 'note' => 'Assessment: ' . $row['title'] . ' (' . (float) $row['score'] . '%)'];
            }
        }
        return $out;
    }

    /**
     * Published, open roles from approved companies, with their requirement
     * lists attached. This is the only job source for every recommendation.
     */
    public function openJobs(): array
    {
        try {
            $statement = $this->db->query(
                "SELECT j.*, c.name AS company_name FROM jobs j JOIN companies c ON c.id = j.company_id "
                . "WHERE j.status = 'published' AND c.approval_status = 'approved' "
                . 'AND (j.closes_at IS NULL OR j.closes_at >= NOW()) ORDER BY j.created_at DESC LIMIT 200',
            );
            $jobs = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $exception) {
            return [];
        }
        return $this->attachSkills($jobs);
    }

    public function job(int $jobId): ?array
    {
        try {
            $statement = $this->db->prepare(
                'SELECT j.*, c.name AS company_name FROM jobs j JOIN companies c ON c.id = j.company_id WHERE j.id = ? LIMIT 1',
            );
            $statement->execute([$jobId]);
            $job = $statement->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $exception) {
            return null;
        }
        if (!$job) {
            return null;
        }
        return $this->attachSkills([$job])[0];
    }

    private function attachSkills(array $jobs): array
    {
        if ($jobs === []) {
            return [];
        }
        $ids = array_map(static fn (array $job): int => (int) $job['id'], $jobs);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        try {
            $statement = $this->db->prepare(
                'SELECT js.job_id, js.requirement_type, s.name FROM job_skills js JOIN skills s ON s.id = js.skill_id '
                . 'WHERE js.job_id IN (' . $placeholders . ') ORDER BY s.name',
            );
            $statement->execute($ids);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $exception) {
            $rows = [];
        }
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['job_id']][$row['requirement_type'] === 'preferred' ? 'preferred' : 'required'][] = (string) $row['name'];
        }
        foreach ($jobs as &$job) {
            $job['required_skills'] = $map[(int) $job['id']]['required'] ?? [];
            $job['preferred_skills'] = $map[(int) $job['id']]['preferred'] ?? [];
        }
        unset($job);
        return $jobs;
    }

    /** Academic score for one job, from the student's CGPA and the role minimum. */
    public function academicScore(array $profile, array $job): float
    {
        $cgpa = isset($profile['cgpa']) && $profile['cgpa'] !== '' ? (float) $profile['cgpa'] : null;
        $min = (float) ($job['min_cgpa'] ?? 0);
        if ($cgpa === null) {
            return 0.0;
        }
        return $min <= 0 ? 100.0 : min(100.0, round($cgpa / $min * 100, 1));
    }

    public function applications(int $studentId): array
    {
        try {
            $statement = $this->db->prepare(
                'SELECT a.id, a.status, a.applied_at, j.title, j.location, c.name AS company_name '
                . 'FROM applications a JOIN jobs j ON j.id = a.job_id JOIN companies c ON c.id = j.company_id '
                . 'WHERE a.student_id = ? ORDER BY a.applied_at DESC',
            );
            $statement->execute([$studentId]);
            return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $exception) {
            return [];
        }
    }

    public function interviews(int $studentId): array
    {
        try {
            $statement = $this->db->prepare(
                'SELECT i.scheduled_at, i.mode, i.location_or_link, j.title, c.name AS company_name '
                . 'FROM interviews i JOIN applications a ON a.id = i.application_id JOIN jobs j ON j.id = a.job_id '
                . 'JOIN companies c ON c.id = j.company_id WHERE a.student_id = ? '
                . "AND i.status = 'scheduled' AND i.scheduled_at >= NOW() ORDER BY i.scheduled_at LIMIT 10",
            );
            $statement->execute([$studentId]);
            return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $exception) {
            return [];
        }
    }

    public function offers(int $studentId): array
    {
        try {
            $statement = $this->db->prepare(
                'SELECT o.ctc_lpa AS ctc, o.status, j.title, c.name AS company_name '
                . 'FROM offers o JOIN applications a ON a.id = o.application_id JOIN jobs j ON j.id = a.job_id '
                . 'JOIN companies c ON c.id = j.company_id WHERE a.student_id = ? ORDER BY o.id DESC',
            );
            $statement->execute([$studentId]);
            return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $exception) {
            return [];
        }
    }

    public function assessmentHistory(int $studentId): array
    {
        try {
            $statement = $this->db->prepare(
                'SELECT at.id, at.score, at.submitted_at, a.title FROM assessment_attempts at JOIN assessments a ON a.id = at.assessment_id '
                . 'WHERE at.student_id = ? AND at.submitted_at IS NOT NULL ORDER BY at.submitted_at DESC LIMIT 20',
            );
            $statement->execute([$studentId]);
            return array_map(static function (array $row): array {
                $row['percent'] = (float) $row['score'];
                return $row;
            }, $statement->fetchAll(PDO::FETCH_ASSOC) ?: []);
        } catch (Throwable $exception) {
            return [];
        }
    }
}
