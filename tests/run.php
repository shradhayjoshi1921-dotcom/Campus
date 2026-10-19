<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Services/EligibilityService.php';
require dirname(__DIR__) . '/app/Services/CareerFitService.php';
require dirname(__DIR__) . '/app/Services/SkillGapService.php';
require dirname(__DIR__) . '/app/Services/RecommendationService.php';
require dirname(__DIR__) . '/app/Services/RoadmapService.php';

use App\Services\CareerFitService;
use App\Services\EligibilityService;
use App\Services\RecommendationService;
use App\Services\RoadmapService;
use App\Services\SkillGapService;

$passed = 0;
$failed = 0;
$test = static function (string $name, callable $fn) use (&$passed, &$failed): void {
    try {
        $fn();
        $passed++;
        echo "PASS {$name}\n";
    } catch (Throwable $e) {
        $failed++;
        echo "FAIL {$name}: {$e->getMessage()}\n";
    }
};
$assert = static fn (bool $condition, string $message = 'assertion failed'): bool => $condition ?: throw new RuntimeException($message);

$test('Eligibility passes matching profile', static function () use ($assert): void {
    $result = (new EligibilityService())->evaluate(
        ['cgpa' => 8.1, 'branch' => 'CSE', 'graduation_year' => 2027],
        ['min_cgpa' => 7.0, 'eligible_branch' => 'CSE', 'graduation_year' => 2027]
    );
    $assert($result['eligible']);
    $assert(count($result['failed']) === 0);
});

$test('Eligibility rejects hard CGPA failure', static function () use ($assert): void {
    $result = (new EligibilityService())->evaluate(
        ['cgpa' => 6.2, 'branch' => 'CSE'],
        ['min_cgpa' => 7.0, 'eligible_branch' => 'CSE']
    );
    $assert(!$result['eligible']);
    $assert(count($result['failed']) === 1);
});

$test('Missing qualification is not silently eligible', static function () use ($assert): void {
    $result = (new EligibilityService())->evaluate(['branch' => 'CSE'], ['min_cgpa' => 6.0]);
    $assert(!$result['eligible']);
    $assert(count($result['missing']) === 1);
});

$test('Fit score uses documented weights and reports gaps', static function () use ($assert): void {
    $result = (new CareerFitService())->score(['PHP', 'SQL'], ['php', 'javascript'], 80, 100);
    $assert($result['score'] === 65.0, (string) $result['score']);
    $assert($result['matched_skills'] === ['php']);
    $assert($result['missing_skills'] === ['javascript']);
});

$test('Skill-gap analysis separates mandatory and preferred', static function () use ($assert): void {
    $result = (new SkillGapService())->analyze(['PHP'], ['PHP', 'SQL'], ['React']);
    $assert($result['mandatory_missing'] === ['sql']);
    $assert($result['preferred_missing'] === ['react']);
});

$test('Roadmap gives an actionable fallback for an unknown skill', static function () use ($assert): void {
    $result = (new RoadmapService())->build(['Rust']);
    $assert($result[0]['skill'] === 'Rust' && str_contains($result[0]['practice'], 'fundamentals'));
});

$test('Recommendations exclude unpublished jobs', static function () use ($assert): void {
    $result = (new RecommendationService())->rank(
        [['status' => 'draft', 'required_skills' => []], ['status' => 'published', 'required_skills' => []]],
        [],
        ['cgpa' => 8, 'branch' => 'CSE'],
        new EligibilityService(),
        new CareerFitService()
    );
    $assert(count($result) === 1);
    $assert($result[0]['job']['status'] === 'published');
});

$root = dirname(__DIR__);
$schema = file_get_contents($root . '/database/schema.sql');
$front = file_get_contents($root . '/public/index.php');
$admin = file_get_contents($root . '/public/admin.php');
$career = file_get_contents($root . '/public/career.php');
$adminSetup = file_get_contents($root . '/scripts/create-admin.php');

$test('Schema seeds no fake placement records', static function () use ($assert, $schema): void {
    $assert(!preg_match('/INSERT\s+INTO\s+(users|companies|jobs|applications)\b/i', $schema));
});

$test('Registration and sign-in forms include CSRF and password hashing', static function () use ($assert, $front): void {
    $assert(substr_count($front, 'name="_csrf"') >= 2);
    $assert(str_contains($front, 'password_hash('));
    $assert(str_contains($front, 'password_verify('));
});

$test('Student profile and skill updates are role-guarded', static function () use ($assert, $front): void {
    $assert(str_contains($front, 'save_profile'));
    $assert(str_contains($front, 'save_skills'));
    $assert(str_contains($front, "Security::requireRole(['student'])"));
    $assert(str_contains($front, 'INSERT INTO student_profiles'));
});

$test('Admin portal restricts access to TPO and admin roles', static function () use ($assert, $admin): void {
    $assert(str_contains($admin, "Security::requireRole(['tpo','admin'])"));
});

$test('Admin actions verify CSRF before state changes', static function () use ($assert, $admin): void {
    $assert(str_contains($admin, 'Security::verifyCsrf'));
    $assert(str_contains($admin, 'user_decision'));
});

$test('Admin portal requires company approval before publishing jobs', static function () use ($assert, $admin): void {
    $assert(str_contains($admin, 'Approve the company before publishing its job.'));
});

$test('Career insights are student-only and include skill-gap and roadmap services', static function () use ($assert, $career): void {
    $assert(str_contains($career, "Security::requireRole(['student'])"));
    $assert(str_contains($career, 'RecommendationService'));
    $assert(str_contains($career, 'SkillGapService'));
    $assert(str_contains($career, 'RoadmapService'));
});

$test('Job listing only exposes published jobs from approved companies', static function () use ($assert, $front): void {
    $assert(str_contains($front, "j.status='published' AND c.approval_status='approved'"));
    $assert(str_contains($front, 'j.closes_at IS NULL OR j.closes_at>=NOW()'));
});

$test('Applications require student role and CSRF validation', static function () use ($assert, $front): void {
    $assert(str_contains($front, "Security::requireRole(['student'])"));
    $assert(str_contains($front, 'apply_job'));
    $assert(str_contains($front, 'name="_csrf"'));
});

$test('Database prevents duplicate applications', static function () use ($assert, $schema): void {
    $assert(str_contains($schema, 'UNIQUE KEY uq_job_student(job_id,student_id)'));
});

$test('Application submission records status history', static function () use ($assert, $front): void {
    $assert(str_contains($front, 'application_status_history'));
    $assert(str_contains($front, 'Application submitted by student'));
});

$test('Admin bootstrap is CLI-only, password-hashed, and refuses a second admin', static function () use ($assert, $adminSetup): void {
    $assert(str_contains($adminSetup, "PHP_SAPI !== 'cli'"));
    $assert(str_contains($adminSetup, 'password_hash('));
    $assert(str_contains($adminSetup, "role='admin'"));
    $assert(str_contains($adminSetup, 'initial bootstrap is disabled'));
});

$test('Homepage uses local assets and an accessible responsive navigation', static function () use ($assert, $front, $root): void {
    $assert(str_contains($front, 'assets/images/campus-hero.jpg'));
    $assert(str_contains($front, 'assets/js/site.js'));
    $assert(is_file($root . '/public/assets/js/site.js'));
    $assert(str_contains($front, 'id="nav-toggle"'));
    $assert(str_contains($front, 'aria-expanded="false"'));
    $assert(is_file($root . '/public/assets/images/campus-hero.jpg'));
    $assert(!str_contains($front, 'cdn.jsdelivr.net'));
    $assert(!str_contains($front, 'unpkg.com'));
});

$test('Workspace sign-out forms submit a CSRF-protected POST', static function () use ($assert, $root): void {
    foreach (['public/index.php', 'public/career.php', 'public/recruiter.php', 'public/admin.php'] as $file) {
        $contents = file_get_contents($root . '/' . $file);
        $assert(str_contains($contents, 'name="action" value="logout"'), "logout action missing in {$file}");
        $assert(str_contains($contents, 'name="_csrf"'), "CSRF token missing in {$file}");
    }
});

$test('No merge-conflict markers remain in key project files', static function () use ($assert, $root): void {
    foreach (['README.md', 'scripts/create-admin.php', 'tests/run.php'] as $file) {
        $contents = file_get_contents($root . '/' . $file);
        $assert(!preg_match('/^(<<<<<<<|=======|>>>>>>>)/m', $contents), "conflict marker in {$file}");
    }
});

echo "\nResults: {$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
