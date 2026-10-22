<?php
declare(strict_types=1);

namespace App\Data;

use App\Services\EvidenceService;
use App\Services\InstitutionService;
use App\Services\ReadinessService;
use App\Services\ScoringModel;
use PDO;
use Throwable;

/**
 * The Training & Placement Cell's intelligence report.
 *
 * One read-only pass over the tables Campus already has, assembled once per
 * request, then handed to InstitutionService for the arithmetic. Everything
 * here is aggregate or a record id — no student name is rendered in the
 * intelligence view, and nothing is written.
 *
 * A missing optional table (an install that has not migrated) returns empty
 * evidence for that section instead of failing the whole page; `data_quality`
 * says which sections had nothing to read.
 */
final class InstitutionReport
{
    private InstitutionService $service;
    private EvidenceService $evidence;

    public function __construct(private readonly PDO $db, private readonly ScoringModel $model)
    {
        $this->service = new InstitutionService();
        $this->evidence = new EvidenceService($model);
    }

    /** @return array<string,mixed> */
    public function build(): array
    {
        $students = $this->rows(
            "SELECT u.id, u.name, p.branch, p.degree, p.cgpa, p.graduation_year, p.phone, p.bio "
            . "FROM users u LEFT JOIN student_profiles p ON p.user_id = u.id "
            . "WHERE u.role = 'student' AND u.status = 'active' ORDER BY u.id",
        );
        $resumes = [];
        foreach ($this->rows('SELECT student_id, id, created_at FROM resumes ORDER BY created_at') as $row) {
            $resumes[(int) $row['student_id']] ??= $row;
        }
        $analyses = [];
        foreach ($this->rows('SELECT student_id, extracted_at, approved_at FROM resume_analyses') as $row) {
            $analyses[(int) $row['student_id']] = $row;
        }
        $skills = [];
        foreach ($this->rows('SELECT ss.student_id, s.name FROM student_skills ss JOIN skills s ON s.id = ss.skill_id') as $row) {
            $skills[(int) $row['student_id']][] = ['name' => (string) $row['name'], 'proficiency' => 'beginner', 'verified' => 0];
        }
        $evidenceRows = [];
        foreach ($this->rows('SELECT student_id, skill_name, level FROM skill_evidence') as $row) {
            $evidenceRows[(int) $row['student_id']][] = [
                'skill' => (string) $row['skill_name'],
                'level' => (string) $row['level'],
                'note' => 'Recorded evidence',
            ];
        }
        $projects = [];
        foreach ($this->rows('SELECT student_id, title, description, skills_json FROM student_projects') as $row) {
            $projects[(int) $row['student_id']][] = $row;
        }
        $certifications = [];
        foreach ($this->rows('SELECT student_id, title, issuer FROM student_certifications') as $row) {
            $certifications[(int) $row['student_id']][] = $row;
        }
        $attempts = [];
        foreach ($this->rows(
            'SELECT at.student_id, at.score, a.title FROM assessment_attempts at JOIN assessments a ON a.id = at.assessment_id '
            . 'WHERE at.submitted_at IS NOT NULL',
        ) as $row) {
            $attempts[(int) $row['student_id']][] = ['percent' => (float) $row['score'], 'title' => (string) $row['title']];
        }
        $practice = [];
        foreach ($this->rows("SELECT student_id, average_score FROM practice_sessions WHERE status = 'completed'") as $row) {
            $practice[(int) $row['student_id']][] = ['score' => $row['average_score'] === null ? null : (float) $row['average_score']];
        }

        $readiness = new ReadinessService($this->model);
        $studentRows = [];
        foreach ($students as $student) {
            $id = (int) $student['id'];
            $evidence = $this->evidence->build(
                $skills[$id] ?? [],
                $projects[$id] ?? [],
                $certifications[$id] ?? [],
                $evidenceRows[$id] ?? [],
            );
            $score = $readiness->score([
                'profile' => $student,
                'resume' => $resumes[$id] ?? null,
                'resume_analysis' => $analyses[$id] ?? null,
                'skills' => $skills[$id] ?? [],
                'evidence' => $evidence,
                'projects' => $projects[$id] ?? [],
                'certifications' => $certifications[$id] ?? [],
                'assessments' => $attempts[$id] ?? [],
                'practice' => $practice[$id] ?? [],
            ]);
            $studentRows[] = [
                'id' => $id,
                'branch' => (string) ($student['branch'] ?? ''),
                'readiness' => $score['total'],
            ];
        }

        // Requirement rows for published roles of approved companies only.
        $requirements = $this->rows(
            "SELECT j.id AS job_id, j.title AS job_title, c.name AS company, s.name AS skill, js.requirement_type "
            . 'FROM job_skills js JOIN jobs j ON j.id = js.job_id JOIN companies c ON c.id = j.company_id '
            . "JOIN skills s ON s.id = js.skill_id WHERE j.status = 'published' AND c.approval_status = 'approved'",
        );
        $demandRows = array_map(static fn (array $row): array => [
            'skill' => (string) $row['skill'],
            'type' => (string) $row['requirement_type'],
            'job' => (string) $row['job_id'],
            'company' => (string) $row['company'],
            'title' => (string) $row['job_title'],
        ], $requirements);

        $skillDemand = $this->service->skillDemand($demandRows);

        // Training gaps: how many students lack each required skill, and how
        // many open roles ask for it.
        $studentSkillCounts = [];
        foreach ($skills as $studentSkills) {
            foreach (array_unique(array_map(static fn (array $row): string => mb_strtolower((string) $row['name']), $studentSkills)) as $name) {
                if ($name !== '') {
                    $studentSkillCounts[$name] = ($studentSkillCounts[$name] ?? 0) + 1;
                }
            }
        }
        $totalStudents = count($students);
        $requiredBySkill = [];
        foreach ($demandRows as $row) {
            if ($row['type'] !== 'required') {
                continue;
            }
            $key = mb_strtolower($row['skill']);
            $requiredBySkill[$key]['skill'] = $row['skill'];
            $requiredBySkill[$key]['jobs'] = ($requiredBySkill[$key]['jobs'] ?? 0) + 1;
        }
        $gaps = [];
        foreach ($requiredBySkill as $key => $row) {
            $missing = max(0, $totalStudents - (int) ($studentSkillCounts[$key] ?? 0));
            if ($missing > 0) {
                $gaps[] = ['skill' => $row['skill'], 'students' => $missing, 'jobs' => (int) $row['jobs']];
            }
        }
        $trainingGaps = $this->service->trainingGaps($gaps);

        $companies = [];
        foreach ($demandRows as $row) {
            $companies[$row['company']]['company'] = $row['company'];
            $companies[$row['company']]['jobs'][$row['job']] = true;
            if ($row['type'] === 'required') {
                $companies[$row['company']]['required'][mb_strtolower($row['skill'])] = $row['skill'];
            }
        }
        $companyInput = [];
        foreach ($companies as $company) {
            $companyInput[] = [
                'company' => $company['company'],
                'required' => array_values($company['required'] ?? []),
                'jobs' => count($company['jobs']),
            ];
        }
        $companyMatches = $this->service->companyMatches($companyInput, $studentSkillCounts, $totalStudents);

        // Application volume by month, last 12 months, oldest first.
        $series = array_reverse($this->rows(
            "SELECT DATE_FORMAT(applied_at, '%Y-%m') AS period, COUNT(*) AS count FROM applications "
            . 'GROUP BY period ORDER BY period DESC LIMIT 12',
        ));
        $trend = $this->service->trend(array_map(static fn (array $row): array => [
            'period' => (string) $row['period'],
            'count' => (int) $row['count'],
        ], $series));

        return [
            'students' => $totalStudents,
            'measured' => count(array_filter($studentRows, static fn (array $row): bool => $row['readiness'] !== null)),
            'readiness_map' => $this->service->readinessMap($studentRows),
            'skill_demand' => $skillDemand,
            'training_gaps' => $trainingGaps,
            'company_matches' => $companyMatches,
            'trend' => $trend,
            'anomalies' => $this->service->anomalies($this->anomalyRows()),
            'data_quality' => [
                'students without a profile row' => count(array_filter($students, static fn (array $row): bool => ($row['branch'] ?? null) === null)),
                'students without a resume' => max(0, $totalStudents - count(array_intersect(array_keys($resumes), array_column($students, 'id')))),
                'resume analyses run' => count($analyses),
                'assessment attempts recorded' => array_sum(array_map('count', $attempts)),
                'practice sessions completed' => array_sum(array_map('count', $practice)),
            ],
            'notice' => 'Aggregates from Campus records only. Readiness is preparation, not a placement prediction; flags below are review prompts, not findings.',
        ];
    }

    /**
     * Things a human should look at. Each row is a record reference, never a
     * name, and never an accusation.
     *
     * @return array<int,array{type:string,detail:string,record:string}>
     */
    private function anomalyRows(): array
    {
        $rows = [];
        foreach ($this->rows('SELECT o.id, o.application_id, o.ctc_lpa FROM offers o WHERE o.ctc_lpa IS NULL OR o.ctc_lpa <= 0 LIMIT 25') as $row) {
            $rows[] = [
                'type' => 'Offer without a package',
                'detail' => 'An offer exists but no CTC is recorded, so placement statistics would under-count it.',
                'record' => 'Offer #' . (int) $row['id'] . ' · application #' . (int) $row['application_id'],
            ];
        }
        foreach ($this->rows('SELECT user_id FROM student_profiles WHERE cgpa = 0 LIMIT 25') as $row) {
            $rows[] = [
                'type' => 'CGPA recorded as zero',
                'detail' => 'A saved CGPA of 0 is usually an empty field rather than a real result.',
                'record' => 'Student profile #' . (int) $row['user_id'],
            ];
        }
        foreach ($this->rows(
            "SELECT j.id FROM jobs j LEFT JOIN job_skills js ON js.job_id = j.id "
            . "WHERE j.status = 'published' AND js.job_id IS NULL LIMIT 25",
        ) as $row) {
            $rows[] = [
                'type' => 'Published job without requirements',
                'detail' => 'Career fit for this role can only use academics until skills are listed.',
                'record' => 'Job #' . (int) $row['id'],
            ];
        }
        foreach ($this->rows(
            "SELECT i.id, i.scheduled_at FROM interviews i WHERE i.status = 'scheduled' AND i.scheduled_at < NOW() LIMIT 25",
        ) as $row) {
            $rows[] = [
                'type' => 'Interview time has passed',
                'detail' => 'Still marked scheduled. Mark it completed or cancelled so the pipeline stays accurate.',
                'record' => 'Interview #' . (int) $row['id'] . ' · ' . (string) $row['scheduled_at'],
            ];
        }
        return $rows;
    }

    /** @return array<int,array<string,mixed>> */
    private function rows(string $sql): array
    {
        try {
            return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $exception) {
            // Optional table missing, or a schema that predates a column.
            error_log('Institution report query skipped: ' . $exception->getMessage());
            return [];
        }
    }
}
