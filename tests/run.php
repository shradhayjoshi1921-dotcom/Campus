<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Services/EligibilityService.php';
require dirname(__DIR__) . '/app/Services/SkillListService.php';
require dirname(__DIR__) . '/app/Services/ScoringModel.php';
require dirname(__DIR__) . '/app/Services/EvidenceService.php';
require dirname(__DIR__) . '/app/Services/CareerFitService.php';
require dirname(__DIR__) . '/app/Services/SkillGapService.php';
require dirname(__DIR__) . '/app/Services/RecommendationService.php';
require dirname(__DIR__) . '/app/Services/RoadmapService.php';
require dirname(__DIR__) . '/app/Services/ReadinessService.php';
require dirname(__DIR__) . '/app/Services/WhatIfService.php';
require dirname(__DIR__) . '/app/Services/CareerGraphService.php';
require dirname(__DIR__) . '/app/Services/ResumeAnalysisService.php';
require dirname(__DIR__) . '/app/Services/JobDescriptionService.php';
require dirname(__DIR__) . '/app/Services/InterviewKitService.php';
require dirname(__DIR__) . '/app/Services/MockInterviewService.php';
require dirname(__DIR__) . '/app/Services/AssessmentService.php';
require dirname(__DIR__) . '/app/Services/InstitutionService.php';
require dirname(__DIR__) . '/app/Services/AdminImportService.php';

use App\Services\CareerFitService;
use App\Services\EligibilityService;
use App\Services\EvidenceService;
use App\Services\RecommendationService;
use App\Services\RoadmapService;
use App\Services\ScoringModel;
use App\Services\SkillGapService;
use App\Services\SkillListService;
use App\Services\AdminImportService;
use App\Services\AssessmentService;
use App\Services\CareerGraphService;
use App\Services\InstitutionService;
use App\Services\InterviewKitService;
use App\Services\JobDescriptionService;
use App\Services\MockInterviewService;
use App\Services\ReadinessService;
use App\Services\ResumeAnalysisService;
use App\Services\WhatIfService;

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
    // weights: skills .45, evidence .20, academic .20, role .15 (config/scoring.php)
    // skills 50%, evidence 25% (two claims), academic 80, role 100 => 58.5
    $result = (new CareerFitService())->score(['PHP', 'SQL'], ['php', 'javascript'], 80, 100);
    $assert($result['score'] === 58.5, (string) $result['score']);
    $assert($result['matched_skills'] === ['php']);
    $assert($result['missing_skills'] === ['javascript']);
    $assert(isset($result['weights']['skills']) && abs($result['weights']['skills'] - 0.45) < 0.0001, 'the weights are reported with the score');
    $assert($result['explanation'] !== [], 'every component is explained');
});

$test('An employer with no listed skills is never scored as a perfect match', static function () use ($assert): void {
    $result = (new CareerFitService())->score(['PHP', 'SQL'], [], 80, 100);
    $assert($result['skills_listed'] === false, 'the caller can tell there was nothing to match');
    $assert($result['components']['skills'] === null, 'no skill component is invented');
    $assert($result['score'] < 100, 'an empty requirement list must not read as 100%');
    $assert(str_contains($result['notice'], 'not listed required skills'), 'the page can explain the fallback');
    $listed = (new CareerFitService())->score(['PHP'], ['PHP'], 100, 100);
    $assert($listed['skills_listed'] === true, 'a listed requirement is matched');
    $assert($listed['components']['skills'] === 100.0, 'the skill itself matches fully');
    $assert($listed['components']['evidence'] === 25.0, 'a claim alone is worth a quarter of the evidence weight');
    $assert($listed['score'] === 85.0, 'a listed skill whose only evidence is a claim is not a perfect match');
    $demonstrated = (new CareerFitService())->score(['PHP'], ['PHP'], 100, 100, [
        'evidence' => ['php' => ['level' => 'project', 'value' => 0.6, 'sources' => ['Portfolio project']]],
    ]);
    $assert($demonstrated['components']['evidence'] === 60.0, 'a demonstrated skill raises the evidence component');
});

$test('The job skills parser cleans and de-duplicates free text', static function () use ($assert): void {
    $parser = new \App\Services\SkillListService();
    $assert($parser->parse('PHP,  SQL ; php, Git') === ['PHP', 'SQL', 'Git'], 'case-insensitive duplicates collapse and whitespace is trimmed');
    $assert($parser->parse("Node.js\nReact\n\nnode.js") === ['Node.js', 'React'], 'newlines separate entries too');
    $assert($parser->parse('   ,  , ') === [], 'empty entries are dropped');
    $assert($parser->parse(['PHP']) === [], 'a non-string field is ignored, never coerced');
    $assert(count($parser->parse(implode(',', range(1, 40)))) === 20, 'one paste cannot exceed the cap');
    $assert($parser->parse(str_repeat('a', 200))[0] === str_repeat('a', 60), 'long names are truncated, not rejected');
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
$recruiter = file_get_contents($root . '/public/recruiter.php');
$recruiterLayout = file_get_contents($root . '/public/views/recruiter/layout-end.php');
$recruiterLayoutStart = file_get_contents($root . '/public/views/recruiter/layout-start.php');
$recruiterJobs = file_get_contents($root . '/public/views/recruiter/jobs.php');
$recruiterApplicants = file_get_contents($root . '/public/views/recruiter/applicants.php');
$recruiterInterviews = file_get_contents($root . '/public/views/recruiter/interviews.php');
$recruiterStyles = file_get_contents($root . '/public/assets/css/recruiter.css');
$recruiterNavScript = file_get_contents($root . '/public/assets/js/recruiter-nav.js');
$adminController = file_get_contents($root . '/public/admin.php');
$adminLayoutStart = file_get_contents($root . '/public/views/admin/layout-start.php');
$adminLayoutEnd = file_get_contents($root . '/public/views/admin/layout-end.php');
$adminStyles = file_get_contents($root . '/public/assets/css/admin.css');
$adminNavScript = file_get_contents($root . '/public/assets/js/admin-nav.js');
$adminImportScript = file_get_contents($root . '/app/Services/AdminImportService.php');
$liquidGlassStyles = file_get_contents($root . '/public/assets/css/liquid-glass.css');
$liquidGlassScript = file_get_contents($root . '/public/assets/js/liquid-glass.js');
$database = file_get_contents($root . '/app/Core/Database.php');
$bootstrap = file_get_contents($root . '/bootstrap/app.php');
$environmentExample = file_get_contents($root . '/.env.example');
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

$test('Portal routes provide accurate not-found and recruiter-approval feedback', static function () use ($assert, $front): void {
    $assert(str_contains($front, 'http_response_code(404)'));
    $assert(str_contains($front, 'awaiting Training & Placement Cell approval'));
    $assert(str_contains($front, '$selectedRole'));
    $assert(str_contains($front, 'value="recruiter"'));
});

$test('Homepage uses local assets, avoids unverified campus photography, and keeps accessible responsive navigation', static function () use ($assert, $front, $root): void {
    $styles = file_get_contents($root . '/public/assets/css/app.css');
    $homepageStyles = file_get_contents($root . '/public/assets/css/homepage.css');
    $assert(str_contains($front, 'assets/css/app.css?v='));
    $assert(str_contains($front, 'assets/css/homepage.css'));
    $assert(str_contains($front, 'assets/js/site.js'));
    $assert(is_file($root . '/public/assets/css/app.css'));
    $assert(is_file($root . '/public/assets/css/homepage.css'));
    $assert(is_file($root . '/public/assets/js/site.js'));
    $assert(!str_contains($front, 'campus-hero.jpg'));
    $assert(!str_contains($styles, 'campus-hero.jpg'));
    $assert(!str_contains($homepageStyles, 'campus-hero.jpg'));
    $assert(str_contains($front, 'data-bubble-menu'), 'the floating bubble menu replaces the top bar');
    $assert(str_contains($front, 'aria-expanded="false"'));
    $assert(str_contains($front, 'class="bubble-top"'), 'navigation is a bubble cluster, not a full-width bar');
    $assert(!str_contains($front, '<header class="site-nav">'), 'the old top bar must stay removed');
    $assert(str_contains($front, '<a class="skip-link" href="#main-content">Skip to main content</a>'));
    $assert((bool) preg_match('/\.skip-link\s*\{[^}]*opacity:\s*0;/s', $styles));
    $assert((bool) preg_match('/\.skip-link:focus-visible\s*\{[^}]*opacity:\s*1;/s', $styles));
    $assert(str_contains($front, 'role="group" aria-label="Portal access'));
    $assert(!str_contains($front, 'cdn.jsdelivr.net'));
    $assert(!str_contains($front, 'unpkg.com'));
});

$test('Workspace sign-out forms submit a CSRF-protected POST', static function () use ($assert, $root): void {
    foreach (['public/index.php', 'public/views/partials/studio-start.php'] as $file) {
        $contents = file_get_contents($root . '/' . $file);
        $assert(str_contains($contents, 'name="action" value="logout"'), "logout action missing in {$file}");
        $assert(str_contains($contents, 'name="_csrf"'), "CSRF token missing in {$file}");
    }
    // Every career studio page takes its chrome (and its sign-out form) from
    // that same shell, so there is exactly one place to protect.
    foreach (['career', 'resume-review', 'practice'] as $studioPage) {
        $contents = file_get_contents($root . '/public/' . $studioPage . '.php');
        $assert(str_contains($contents, 'views/partials/studio-start.php'), "{$studioPage}.php does not use the studio shell");
        $assert(str_contains($contents, "Security::requireRole(['student'])"), "{$studioPage}.php is not student-only");
    }
    $adminLayout = file_get_contents($root . '/public/views/admin/layout-start.php');
    $assert(str_contains($adminLayout, 'name="action" value="logout"'), 'logout action missing in admin layout');
    $assert(str_contains($adminLayout, 'name="_csrf"'), 'CSRF token missing in admin layout');
    $recruiterLayout = file_get_contents($root . '/public/views/recruiter/layout-start.php');
    $assert(str_contains($recruiterLayout, 'name="action" value="logout"'), 'logout action missing in recruiter layout');
    $assert(str_contains($recruiterLayout, 'name="_csrf"'), 'CSRF token missing in recruiter layout');
});

$test('Recruiter workspace is role-guarded and mutations use CSRF-protected PRG', static function () use ($assert, $recruiter): void {
    $assert(str_contains($recruiter, "Security::requireRole(['recruiter'])"));
    $assert(str_contains($recruiter, 'Security::verifyCsrf'));
    $assert(str_contains($recruiter, "true, 303"));
    $assert(str_contains($recruiter, 'WHERE id = ? AND company_id = ?'));
    $assert(str_contains($recruiter, 'j.company_id = ?'));
});

$test('Approved recruiters can publish directly while company approval is checked under lock', static function () use ($assert, $recruiter, $recruiterJobs, $admin): void {
    $assert(str_contains($recruiter, 'SELECT c.approval_status FROM recruiter_profiles rp JOIN companies c'));
    $assert(str_contains($recruiter, 'if ($status === \'published\' && $companyApprovalStatus !== \'approved\')'));
    $assert(str_contains($recruiterJobs, 'Approved companies can publish roles directly'));
    $assert(str_contains($recruiterJobs, 'locked until approval'));
    $assert(str_contains($admin, "'pause' => 'paused'"));
    $assert(str_contains($admin, "'close' => 'closed'"));
    $assert(str_contains($admin, 'Approve the company before publishing its job.'));
});

$test('Company profile edits preserve approval only when material details are unchanged', static function () use ($assert, $recruiter): void {
    $assert(str_contains($recruiter, 'SELECT company_id FROM recruiter_profiles WHERE user_id = ? FOR UPDATE'));
    $assert(str_contains($recruiter, 'SELECT name AS company_name, website, industry, description, approval_status FROM companies WHERE id = ? FOR UPDATE'));
    $assert(str_contains($recruiter, '($currentCompany[\'approval_status\'] ?? \'\') === \'approved\' && !$materialChange'));
    $assert(str_contains($recruiter, 'designation = VALUES(designation)'));
});

$test('Recruiter applicant pipeline validates transitions, records history, and notifies students', static function () use ($assert, $recruiter, $recruiterApplicants): void {
    $assert(str_contains($recruiter, "'submitted' => ['under_review', 'rejected']"));
    $assert(str_contains($recruiter, 'INSERT INTO application_status_history'));
    $assert(str_contains($recruiter, 'Application status updated'));
    $assert(str_contains($recruiterApplicants, 'Recent stage history'));
    $assert(str_contains($recruiterApplicants, 'mailto:'));
    $assert(str_contains($recruiter, 'application_id IN ('));
});

$test('Interview workflow supports scheduling, rescheduling, cancellation, and completion', static function () use ($assert, $recruiter, $recruiterInterviews, $schema): void {
    foreach (['schedule_interview', 'reschedule_interview', 'cancel_interview', 'complete_interview'] as $action) {
        $assert(str_contains($recruiter, "'{$action}'"), "missing recruiter interview action {$action}");
    }
    $assert(str_contains($recruiter, 'Interview rescheduled'));
    $assert(str_contains($recruiter, 'Interview cancelled'));
    $assert(str_contains($recruiterInterviews, 'rec-reschedule-details'));
    $assert(str_contains($recruiterInterviews, 'Open meeting link'));
    $assert(str_contains($schema, 'CREATE TABLE IF NOT EXISTS interviews'));
});

$test('Portal date handling defaults consistently to SVIET India Standard Time', static function () use ($assert, $bootstrap, $database, $environmentExample): void {
    $assert(str_contains($bootstrap, "Env::get('APP_TIMEZONE', 'Asia/Kolkata')"));
    $assert(str_contains($bootstrap, 'date_default_timezone_set($appTimezone)'));
    $assert(str_contains($database, "Env::get('DB_TIMEZONE', '+05:30')"));
    $assert(str_contains($database, 'SET time_zone = '));
    $assert(str_contains($database, '->quote($databaseTimezone)'));
    $assert(str_contains($environmentExample, 'APP_TIMEZONE=Asia/Kolkata'));
    $assert(str_contains($environmentExample, 'DB_TIMEZONE=+05:30'));
});

$test('Recruiter navigation is a gesture-driven liquid dock wired for safe client-side sections', static function () use ($assert, $recruiterLayout, $recruiterLayoutStart, $recruiterStyles, $recruiterNavScript): void {
    $assert(str_contains($recruiterLayoutStart, 'id="recruiter-section-content"'));
    $assert(str_contains($recruiterLayout, 'id="recruiter-nav-status"'));
    $assert(str_contains($recruiterLayout, 'aria-live="polite"'));
    $assert(str_contains($recruiterNavScript, 'window.history.pushState'));
    $assert(str_contains($recruiterNavScript, "'popstate'"));
    $assert(str_contains($recruiterNavScript, "addEventListener('submit'"));
    $assert(str_contains($recruiterNavScript, 'DOMParser'));
    $assert(str_contains($recruiterNavScript, 'prefers-reduced-motion'));
    $assert(str_contains($recruiterNavScript, 'is-pending'));
    $assert(str_contains($recruiterStyles, '.rec-page-content.is-pending[data-direction="forward"]'));
    $assert(str_contains($recruiterStyles, '.rec-page-content.is-entering[data-direction="backward"]'));
    $assert(str_contains($recruiterStyles, '.rec-page-content.is-swiping { transition: none; }'));
    $assert(str_contains($recruiterStyles, '.rec-bottom-nav.is-dragging'));
    $assert(str_contains($recruiterStyles, 'touch-action: pan-y'));
    $assert(str_contains($recruiterStyles, '@keyframes rec-section-enter-forward'));
    $assert(str_contains($recruiterStyles, '@keyframes rec-section-enter-backward'));
    $assert(str_contains($recruiterStyles, 'user-select: none'));
});

$test('Recruiter views provide a responsive liquid-glass dock with a persistent sliding bubble', static function () use ($assert, $recruiterLayout, $recruiterStyles, $recruiterNavScript, $root): void {
    $assert(str_contains($recruiterLayout, 'class="rec-bottom-nav" data-glass-dock'));
    $assert(str_contains($recruiterLayout, 'class="lg-indicator"'));
    $assert(str_contains($recruiterLayout, 'aria-label="Recruiter workspace"'));
    $assert(str_contains($recruiterLayout, 'assets/js/liquid-glass.js?v='));
    $assert(str_contains($recruiterLayout, 'assets/js/recruiter-nav.js?v='));
    $assert(str_contains($recruiterStyles, 'backdrop-filter: blur(26px) saturate(155%)'), 'the recruiter dock is real glass');
    $assert(!str_contains($recruiterStyles, 'rec-liquid-wobble'));
    $assert(str_contains($recruiterStyles, 'env(safe-area-inset-bottom'));
    $assert(str_contains($recruiterStyles, '@media (prefers-reduced-motion: reduce)'));
    $assert(str_contains($recruiterNavScript, 'LiquidGlass.mount'));
    $assert(str_contains($recruiterNavScript, 'restoreKey'), 'the capsule flows in from the previous tab');
    $assert(str_contains($recruiterNavScript, 'liquidglass:activate'));
    $assert(str_contains($recruiterNavScript, 'prefers-reduced-motion'));
    foreach (['overview', 'company', 'jobs', 'applicants', 'interviews'] as $view) {
        $assert(is_file($root . '/public/views/recruiter/' . $view . '.php'), "missing recruiter view {$view}");
        $assert(str_contains($recruiterLayout, 'data-rec-section="' . $view . '"'));
    }
});

$test('Admin import parses a CSV and separates ready rows from invalid ones', static function () use ($assert): void {
    $service = new AdminImportService();
    $csv = "name,email,role,branch,cgpa,graduation_year,company,designation\n"
        . "Aarav Sharma,AARAV@example.edu,student,CSE,8.4,2027,,\n"
        . "Neha Kapoor,neha@example.com,recruiter,,,,Northwind Labs,Talent Partner\n"
        . "Bad Row,not-an-email,student,CSE,12,2019,,\n"
        . "No Company,recruiter2@example.com,recruiter,,,,\n";
    $parsed = $service->parse($csv);

    $assert($parsed['errors'] === [], 'unexpected file-level errors');
    $assert(count($parsed['rows']) === 4, 'expected four data rows');
    $assert($parsed['rows'][0]['valid'] === true);
    $assert($parsed['rows'][0]['values']['email'] === 'aarav@example.edu', 'emails are normalised to lowercase');
    $assert($parsed['rows'][1]['valid'] === true, 'recruiter row with a company is valid');
    $assert($parsed['rows'][2]['valid'] === false);
    $assert(str_contains(implode(' ', $parsed['rows'][2]['errors']), 'valid address'));
    $assert(str_contains(implode(' ', $parsed['rows'][2]['errors']), 'CGPA'));
    $assert($parsed['rows'][3]['valid'] === false);
    $assert(str_contains(implode(' ', $parsed['rows'][3]['errors']), 'Company is required'));
});

$test('Admin import understands common spreadsheet header names', static function () use ($assert): void {
    $service = new AdminImportService();
    $parsed = $service->parse("Full Name,E-Mail,Type,Department,Passing Year\nRiya Verma,riya@example.edu,student,ECE,2026\n");
    $assert($parsed['errors'] === [], 'aliases should map onto canonical columns');
    $assert(count($parsed['rows']) === 1);
    $assert($parsed['rows'][0]['valid'] === true);
    $assert($parsed['rows'][0]['values']['branch'] === 'ECE');
    $assert($parsed['rows'][0]['values']['graduation_year'] === '2026');
});

$test('Admin import refuses duplicate emails and impossible batches', static function () use ($assert): void {
    $service = new AdminImportService();

    $duplicate = $service->parse("name,email,role\nA,a@example.edu,student\nB,a@example.edu,student\n");
    $assert(count($duplicate['rows']) === 2);
    $assert($duplicate['rows'][0]['valid'] === true);
    $assert($duplicate['rows'][1]['valid'] === false);
    $assert(str_contains(implode(' ', $duplicate['rows'][1]['errors']), 'Duplicate email'));

    $rows = "name,email,role\n";
    for ($i = 0; $i <= AdminImportService::MAX_ROWS; $i++) {
        $rows .= 'Student ' . $i . ',student' . $i . '@example.edu,student' . "\n";
    }
    $oversized = $service->parse($rows);
    $assert($oversized['rows'] === []);
    $assert(str_contains(implode(' ', $oversized['errors']), (string) AdminImportService::MAX_ROWS));

    $missingHeader = $service->parse("name,branch\nA,CSE\n");
    $assert($missingHeader['rows'] === []);
    $assert(str_contains(implode(' ', $missingHeader['errors']), 'name, email and role'));
});

$test('Admin import issues unique temporary credentials and a clean sheet', static function () use ($assert): void {
    $service = new AdminImportService();
    $passwords = [];
    for ($i = 0; $i < 25; $i++) {
        $password = $service->generatePassword();
        $assert(strlen($password) === 14, 'temporary passwords match the portal minimum');
        $assert(preg_match('/^[A-HJ-NP-Za-km-z2-9]+$/', $password) === 1, 'ambiguous characters are excluded');
        $passwords[$password] = true;
    }
    $assert(count($passwords) === 25, 'passwords must not repeat');

    $csv = AdminImportService::credentialsCsv([
        ['name' => 'Aarav, Sharma', 'email' => 'aarav@example.edu', 'role' => 'student', 'password' => 'Abcdefgh2345Xy'],
    ]);
    $assert(str_starts_with($csv, 'name,email,role') && str_contains($csv, 'temporary password'), 'credential sheet header');
    $assert(str_contains($csv, '"Aarav, Sharma"'), 'commas must stay quoted');
    $assert(str_contains($csv, 'Abcdefgh2345Xy'));
});

$test('Admin portal keeps role guards, CSRF and consistent audit coverage', static function () use ($assert, $adminController): void {
    $assert(str_contains($adminController, "Security::requireRole(['tpo','admin'])"), 'TPO/admin guard must stay first');
    $assert(str_contains($adminController, 'Security::verifyCsrf'), 'all POSTs verify CSRF');
    $assert(str_contains($adminController, "http_response_code(419)"));
    $assert(str_contains($adminController, 'beginTransaction'));
    $assert(str_contains($adminController, 'rollBack'));
    foreach (['user_decision', 'company_decision', 'job_decision', 'announcement', 'offer_create', 'offer_update', 'import_commit'] as $action) {
        $assert(str_contains($adminController, "'{$action}'"), "missing action {$action}");
    }
    $assert(str_contains($adminController, "admin_audit(\$db, (int) \$user['id'], 'accounts_imported'"));
    $assert(str_contains($adminController, "admin_audit(\$db, (int) \$user['id'], 'offer_created'"));
    $assert(str_contains($adminController, "'user_changes_requested'"), 'change requests are audited');
    $assert(str_contains($adminController, 'admin_notify('), 'decisions notify the account holder');
    $assert(!str_contains($adminController, 'password_hash($password, PASSWORD_DEFAULT)  ??'), 'no placeholder hashing');
});

$test('Admin portal has nine routed sections with all views on disk', static function () use ($assert, $adminController, $adminLayoutEnd, $adminLayoutStart, $root): void {
    $sections = ['overview', 'approvals', 'students', 'placements', 'jobs', 'reports', 'comms', 'governance', 'intelligence'];
    foreach (['overview', 'company', 'jobs', 'applicants', 'interviews', 'ai'] as $recruiterSection) {
        $assert(is_file($root . '/public/views/recruiter/' . $recruiterSection . '.php'), "missing recruiter view {$recruiterSection}");
        $assert(str_contains((string) file_get_contents($root . '/public/recruiter.php'), "'{$recruiterSection}'"), "recruiter section {$recruiterSection} is not routable");
    }
    foreach ($sections as $section) {
        $assert(str_contains($adminController, "'{$section}'"), "section {$section} is not routable");
        $assert(is_file($root . '/public/views/admin/' . $section . '.php'), "missing admin view {$section}");
        $assert(str_contains($adminLayoutEnd, "'{$section}' => ["), "dock is missing {$section}");
    }
    $assert(str_contains($adminLayoutStart, 'id="admin-section-content"'));
    $assert(str_contains($adminLayoutEnd, 'id="admin-nav-status"'));
    $assert(str_contains($adminLayoutEnd, 'assets/js/admin-nav.js?v='));
    $assert(str_contains($adminController, "require __DIR__ . '/views/admin/layout-start.php'"));
    $assert(str_contains($adminController, "require __DIR__ . '/views/admin/layout-end.php'"));
});

$test('Admin dock is a scrollable rail with the liquid glass capsule', static function () use ($assert, $adminLayoutEnd, $adminStyles, $adminNavScript): void {
    foreach (['lg-smoke', 'lg-specular', 'lg-rim', 'lg-caustic', 'lg-glint'] as $layer) {
        $assert(str_contains($adminLayoutEnd, $layer), "missing glass layer {$layer}");
    }
    $assert(str_contains($adminLayoutEnd, 'data-glass-dock'));
    $assert(str_contains($adminStyles, 'overflow-x: auto'), 'the rail must scroll');
    $assert(str_contains($adminStyles, 'scrollbar-width: none'));
    $assert(str_contains($adminStyles, 'border-radius: 999px'));
    $assert(str_contains($adminStyles, '@media (prefers-reduced-motion: reduce)'));

    $assert(str_contains($adminNavScript, 'admin-dock-track'));
    $assert(str_contains($adminNavScript, 'LiquidGlass.mount'));
    $assert(str_contains($adminNavScript, 'liquidglass:activate'));
    $assert(str_contains($adminNavScript, 'syncBadge'), 'the approvals badge follows partial loads');
    $assert(!str_contains($adminNavScript, 'setInterval'));
    $assert(str_contains($adminNavScript, "url.searchParams.has('export')"), 'CSV downloads must stay native');
    $assert(str_contains($adminNavScript, 'window.history.pushState'));
    $assert(str_contains($adminNavScript, "addEventListener('popstate'"));
    $assert(str_contains($adminNavScript, 'prefers-reduced-motion'));
});

$test('Offer tracking ships a schema, a migration and honest reporting', static function () use ($assert, $root, $adminController): void {
    $assert(is_file($root . '/database/migrations/2026-10-20-admin-portal-offers.sql'));
    foreach ([$root . '/database/schema.sql', $root . '/database/migrations/2026-10-20-admin-portal-offers.sql'] as $file) {
        $sql = file_get_contents($file);
        $assert(str_contains($sql, 'CREATE TABLE IF NOT EXISTS offers'), basename($file) . ' is missing the offers table');
        $assert(str_contains($sql, 'UNIQUE KEY uq_offer_application'), basename($file) . ' must keep one offer per application');
        $assert(str_contains($sql, "ENUM('offered','accepted','declined','joined','revoked')"));
    }
    $assert(str_contains($adminController, "a.status = 'selected'"), 'offers only attach to selected applications');
    $assert(str_contains($adminController, 'admin_table_exists($db, \'offers\')'), 'a missing table must degrade, not fatal');
    $assert(str_contains($adminController, "o.status = 'joined'"), 'placement rate counts joined offers only');
    $assert(str_contains($adminController, 'median_ctc'));
    $assert(str_contains($adminController, "admin_export_csv('sviet-offers-"));
    $assert(str_contains($adminController, "'sviet-branch-report-'"));
});

$test('Admin import is file-limited, review-first and free of raw inserts from request data', static function () use ($assert, $adminImportScript, $adminController): void {
    $assert(str_contains($adminImportScript, 'MAX_ROWS = 300'));
    $assert(str_contains($adminImportScript, 'MAX_BYTES = 524288'));
    $assert(str_contains($adminImportScript, 'FILTER_VALIDATE_EMAIL'));
    $assert(str_contains($adminImportScript, 'generatePassword'));
    $assert(str_contains($adminController, "'import_preview'"), 'imports must be previewed first');
    $assert(str_contains($adminController, '$_SESSION[\'admin_import\']'), 'preview is staged in the session');
    $assert(str_contains($adminController, 'password_hash($password, PASSWORD_DEFAULT)'), 'temporary passwords are hashed');
    $assert(!str_contains($adminController, 'unserialize('), 'no unserialise of uploads');
    $assert(!str_contains($adminController, 'eval('));
});


$test('Every portal ships the same light-orange and dark-water appearance switch', static function () use ($assert, $root): void {
    $theme = file_get_contents($root . '/public/assets/css/theme.css');
    $assert(str_contains($theme, ':root {'), 'light tokens are the default');
    $assert(str_contains($theme, '--sv-bg: #fff7f0'), 'light surface is warm ivory');
    $assert(str_contains($theme, '--sv-orange: #c96f2d'), 'the ember accent carries body text');
    $assert(str_contains($theme, '--sv-bg: #090a0c'), 'dark mode is premium matte black');
    $assert(str_contains($theme, '--sv-apricot: #f59a55'), 'light apricot is a highlight, not a page wash');
    $assert(!str_contains($theme, '#071c20'), 'the teal water gradient is gone');
    $assert(!preg_match('/#(?:0[4-9]|1[0-9])[0-9a-f]{2}2[0-9a-f]/i', $theme), 'no midnight-teal surfaces remain');
    $assert(str_contains($theme, '--sv-drop:'), 'the dock droplet has a watery fill per theme');
    $assert(str_contains($theme, 'html[data-theme="light"] body.recruiter-portal'));
    $assert(str_contains($theme, 'html[data-theme="light"] body.admin-portal'));
    $assert(str_contains($theme, 'html[data-theme="dark"] body.recruiter-portal'));

    $script = file_get_contents($root . '/public/assets/js/theme.js');
    $assert(str_contains($script, "localStorage.setItem(STORAGE_KEY, next)"));
    $assert(str_contains($script, "data-theme-toggle"));
    $assert(str_contains($script, "root.dataset.theme"));
    $assert(str_contains($script, 'prefers-color-scheme: dark'));

    foreach (['public/index.php', 'public/views/partials/studio-start.php', 'public/views/recruiter/layout-start.php', 'public/views/admin/layout-start.php'] as $file) {
        $contents = file_get_contents($root . '/' . $file);
        $assert(str_contains($contents, 'assets/css/theme.css'), "theme.css missing in {$file}");
        $assert(str_contains($contents, "saved === 'dark'"), "pre-paint bootstrap missing in {$file}");
    }
    foreach (['public/index.php', 'public/views/recruiter/layout-start.php', 'public/views/admin/layout-start.php'] as $file) {
        $assert(str_contains(file_get_contents($root . '/' . $file), 'data-theme-toggle'), "toggle missing in {$file}");
    }
});

$test('One sign-in routes each role to its own workspace', static function () use ($assert, $root): void {
    $index = file_get_contents($root . '/public/index.php');
    $assert(str_contains($index, "'recruiter' => 'recruiter.php'"));
    $assert(str_contains($index, "'tpo', 'admin' => 'admin.php'"));
    $assert(str_contains($index, "default => 'index.php?page=dashboard'"));
    $assert(str_contains($index, 'header(\'Location: \' . $landing, true, 303)'), 'role redirect must be a 303');
    $assert(str_contains($index, 'Your role is read from your account'), 'the login page explains the unified sign-in');
    $assert(str_contains($index, 'auth-role-note'), 'the login page names the three audiences');
    // The login form must not ask the visitor to pick a role; registration still does.
    $authSection = substr($index, strpos($index, "\$page === 'login' || \$page === 'register'"));
    $authSection = substr($authSection, 0, strpos($authSection, "<?php elseif (\$page === 'profile'"));
    $loginForm = $authSection;
    $assert(substr_count($loginForm, 'name="role"') === 2, 'registration offers exactly the two creatable roles');
    $assert(str_contains($loginForm, 'value="student"') && str_contains($loginForm, 'value="recruiter"'), 'both role options are present');
    $assert(substr_count($loginForm, 'name="role"') === substr_count($loginForm, 'type="radio" name="role"'), 'the role field is a radio group, not a second login step');
    $assert(str_contains($loginForm, "value=\"<?= \$isLogin ? 'login' : 'register' ?>\""), 'the submitted action still matches the page');
    $assert(str_contains($loginForm, 'data-password-toggle="account-password"'), 'the password can be revealed without JS-by-default chrome');
    $assert(str_contains($loginForm, "'current-password'") && str_contains($loginForm, "'new-password'"), 'password managers get the right hints');
});

$test('Sign in and registration share one professional entry layout', static function () use ($assert, $root): void {
    $index = file_get_contents($root . '/public/index.php');
    $css = file_get_contents($root . '/public/assets/css/app.css');
    $site = file_get_contents($root . '/public/assets/js/site.js');

    $assert(str_contains($index, 'class="auth-shell"'), 'the entry page is a two-panel layout');
    $assert(str_contains($index, 'class="auth-intro"'), 'the left panel explains the portal');
    $assert(str_contains($index, 'class="auth-field"'), 'fields are grouped with visible labels');
    $assert(str_contains($index, 'class="auth-role-choices"'), 'registration picks a role with radio cards');
    $assert(str_contains($index, 'auth-alert'), 'errors and notices appear inside the card');
    $assert(!preg_match('/\$error \|\| \$success\): \?>\s*<div class="container pt-3">/', $index), 'the old page-top alert no longer double-prints on the auth pages');

    $assert(str_contains($css, '.auth-shell {'), 'the layout has its own grid');
    $assert(str_contains($css, 'grid-template-columns: minmax(0, 1fr) minmax(360px, 448px)'), 'two panels on desktop');
    $assert(str_contains($css, '@media (max-width: 940px)'), 'and a single readable column on tablets');
    $assert(str_contains($css, '.auth-choice input:focus-visible + .auth-choice__body'), 'role cards keep a visible keyboard focus');
    $assert(str_contains($site, 'data-password-toggle'), 'the reveal button is wired');
    $assert(str_contains($site, 'button.hidden = false'), 'the reveal button only appears when it can work');
    $assert(str_contains($index, 'data-busy-label="<?= $isLogin ? \'Signing in…\''), 'the submit button says what it is doing while the form posts');
    $assert(str_contains($site, "button.setAttribute('aria-busy', 'true')"), 'the busy state is exposed to assistive tech');
    $assert(str_contains($site, "window.addEventListener('pageshow'"), 'a back/forward restore re-enables the button');
    $assert(str_contains($css, '.auth-submit[aria-busy="true"]'), 'the busy state is visible, not just disabled');
});

$test('Students can upload, replace, download and remove a PDF resume', static function () use ($assert, $root): void {
    $index = file_get_contents($root . '/public/index.php');
    $assert(str_contains($index, "=== 'upload_resume'"));
    $assert(str_contains($index, "=== 'delete_resume'"));
    $assert(str_contains($index, "\$magic !== '%PDF-'"), 'uploads are checked against the PDF signature');
    $assert(str_contains($index, '5 \\* 1024 \\* 1024') || str_contains($index, '5 * 1024 * 1024'), 'resume cap is 5 MB');
    $assert(str_contains($index, 'preg_match(\'/\\.pdf$/i\''), 'the file name must end in .pdf');
    $assert(str_contains($index, 'storage/uploads/resumes'), 'uploads land outside the document root');
    $assert(str_contains($index, 'application/pdf'), 'downloads are served as PDFs');
    $assert(str_contains($index, 'X-Content-Type-Options: nosniff'));
    $assert(str_contains($index, 'AND r.student_id = ?'), 'students only download their own resume');
    $assert(str_contains($index, 'enctype="multipart/form-data"'));
    $assert(str_contains($index, 'accept="application/pdf,.pdf"'));
});

$test('A recruiter can read a resume only for their own applicants, and it is audited', static function () use ($assert, $root): void {
    $index = file_get_contents($root . '/public/index.php');
    $view = file_get_contents($root . '/public/views/recruiter/applicants.php');
    $assert(str_contains($index, "\$isRecruiter = \$user['role'] === 'recruiter'"), 'recruiters get a scoped branch, not the review branch');
    $assert(str_contains($index, 'JOIN recruiter_profiles rp ON rp.company_id = j.company_id'), 'access is limited to their own company');
    $assert(str_contains($index, 'WHERE a.student_id = r.student_id AND rp.user_id = ?'), 'only students who applied to them');
    $assert(str_contains($index, "'resume.viewed'"), 'every recruiter view is audited');
    $assert(str_contains($index, "hash('sha256', (string) \$_SERVER['REMOTE_ADDR'])"), 'the audit row stores a hashed IP, not a raw one');
    $assert(!preg_match('/\$canReview\s*=\s*in_array\(\$user\[.role.\], \[.tpo., .admin., .recruiter./', $index), 'recruiters are not folded into the unrestricted review list');
    $assert(str_contains($view, 'index.php?page=resume&amp;id=') || str_contains($view, 'index.php?page=resume&id='), 'the applicant card links to the resume');
    $assert(str_contains($view, 'rel="noopener"'), 'the resume opens safely in a new tab');
});

$test('The recruiter job editor records required and preferred skills', static function () use ($assert, $root): void {
    $recruiter = file_get_contents($root . '/public/recruiter.php');
    $view = file_get_contents($root . '/public/views/recruiter/jobs.php');
    $assert(str_contains($recruiter, 'function recruiter_save_job_skills'), 'skills are written to the shared vocabulary');
    $assert(str_contains($recruiter, 'INSERT INTO skills (name) VALUES (?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'), 'an existing skill name is reused, never duplicated');
    $assert(str_contains($recruiter, 'INSERT INTO job_skills (job_id, skill_id, requirement_type)'), 'the link row carries required/preferred');
    $assert(str_contains($recruiter, "recruiter_post('status') ?: 'draft';"), 'the form still posts a status');
    $assert(str_contains($recruiter, "\$skillParser->parse(\$_POST['required_skills'] ?? null)"), 'required skills are parsed from the form');
    $assert(str_contains($recruiter, 'new \\App\\Services\\SkillListService()'), 'the shared parser is used');
    $assert(str_contains($recruiter, 'recruiter_save_job_skills($db, $jobId, $requiredSkills, $preferredSkills)'), 'skills save inside the job transaction');
    $assert(str_contains($recruiter, "array_key_exists('required_skills', \$_POST)"), 'a form that never sent the fields cannot wipe an existing list');
    $assert(str_contains($recruiter, 'DELETE FROM job_skills WHERE job_id = ?'), 'editing replaces the list instead of appending');
    $assert(str_contains($view, 'name="required_skills"') && str_contains($view, 'name="preferred_skills"'), 'both lists are editable');
    $assert(str_contains($view, 'rec-skill-chips'), 'the role cards show the skills students will be scored on');
    $assert(str_contains($view, 'No skills listed yet'), 'an empty list is stated, not silently scored');
});

$test('A student can see their own interviews and offers', static function () use ($assert, $root): void {
    $index = file_get_contents($root . '/public/index.php');
    $assert(str_contains($index, 'JOIN jobs j ON j.id = a.job_id JOIN companies c ON c.id = j.company_id'), 'interviews join back to the application');
    $assert(str_contains($index, "a.student_id = ? AND i.status = 'scheduled' AND i.scheduled_at >= NOW()"), 'only the student\'s own upcoming interviews');
    $assert(str_contains($index, 'FROM offers o JOIN applications a ON a.id = o.application_id'), 'offers are read for the same student');
    $assert(str_contains($index, 'ORDER BY o.offer_date DESC'), 'the newest offer comes first');
    $assert(preg_match('/catch \(Throwable \$exception\) \{\s*\$studentOffers = \[\];/', $index) === 1, 'a database without the offers migration still renders');
    $assert(str_contains($index, 'ctc_lpa'), 'the CTC the recruiter recorded is shown to the candidate');
    $assert(str_contains($index, 'sv-next-list'), 'both the dashboard and the applications page list them');
    $assert(str_contains($index, 'sv-skill-row'), 'the browsable job list shows the skills the fit score uses');
    $assert(str_contains($index, 'This employer has not listed required skills yet'), 'an empty requirement list is explained to the student');
    $assert(str_contains($index, 'rel="noopener noreferrer"'), 'an interview link opens safely');
    $assert(str_contains($index, "preg_match('~^https?://~i', \$joinLink)"), 'only http(s) links become join buttons');
});

$test('Student workspace adds strength, notifications and an application timeline', static function () use ($assert, $root): void {
    $index = file_get_contents($root . '/public/index.php');
    $assert(str_contains($index, 'profileStrength'));
    $assert(str_contains($index, "'PDF resume' => \$resume !== null"));
    $assert(str_contains($index, "=== 'notifications'"));
    $assert(str_contains($index, 'mark_notifications_read'));
    $assert(str_contains($index, 'application_status_history'));
    $assert(str_contains($index, 'sv-timeline'));
    $assert(str_contains($index, 'read_at IS NULL'), 'the dock badge counts unread notices');
    $assert(str_contains($index, 'function sviet_student_dock'));
    $assert(str_contains($index, 'index.php?page=notifications'));
});

$test('Student dock is a draggable water capsule, not five separate buttons', static function () use ($assert, $root): void {
    $styles = file_get_contents($root . '/public/assets/css/water-dock.css');
    $script = file_get_contents($root . '/public/assets/js/student-nav.js');
    $assert(str_contains($styles, '.sv-dock'), 'the dock shell keeps its layout');
    $assert(str_contains($styles, 'body:has(.sv-dock)'), 'content makes room for the dock');
    $assert(str_contains($styles, 'prefers-reduced-motion'));

    $assert(str_contains($script, 'LiquidGlass.mount'));
    $assert(str_contains($script, 'sv-dock-track'), 'the rail is handed to the shared engine');
    $assert(str_contains($script, 'DOMParser'), 'student tabs merge the next page without a reload');
    $assert(str_contains($script, "addEventListener('popstate'"), 'back and forward replay the same motion');
    $assert(str_contains($script, 'replaceWith'), 'only the page canvas is swapped, so the dock never blinks');
    $assert(str_contains($script, 'sviet-student-dock-index'), 'the capsule flows in from the previous workspace');
    $assert(!str_contains($script, 'setInterval'));
});

$test('Recruiters can record a CTC when a candidate is selected', static function () use ($assert, $root): void {
    $recruiter = file_get_contents($root . '/public/recruiter.php');
    $view = file_get_contents($root . '/public/views/recruiter/applicants.php');
    $assert(str_contains($view, 'name="ctc_lpa"'), 'the applicant form collects a CTC');
    $assert(str_contains($view, 'CTC in LPA (on selection)'));
    $assert(str_contains($recruiter, "\$ctcRaw = recruiter_post('ctc_lpa')"));
    $assert(str_contains($recruiter, "'Enter the offered CTC in LPA, between 0 and 500.'"));
    $assert(str_contains($recruiter, "\$nextStatus === 'selected'"));
    $assert(str_contains($recruiter, 'INSERT INTO offers (application_id, ctc_lpa, offer_date, status, created_by)'));
    $assert(str_contains($recruiter, 'recruiter_table_exists($db, \'offers\')'), 'a missing offers table must not break the pipeline');
    $assert(str_contains($recruiter, 'offer.created'));
});

$test('Top navigation is a floating bubble cluster with a watery sheet', static function () use ($assert, $root): void {
    $theme = file_get_contents($root . '/public/assets/css/theme.css');
    $script = file_get_contents($root . '/public/assets/js/theme.js');
    $front = file_get_contents($root . '/public/index.php');

    $assert(str_contains($theme, '.bubble-top {'), 'missing bubble cluster layout');
    $assert(str_contains($theme, '.bubble-sheet__panel'));
    $assert(str_contains($theme, 'backdrop-filter: blur(26px) saturate(185%)'));
    $assert(str_contains($theme, '.bubble-sheet.is-open'));
    $assert(str_contains($script, 'data-bubble-sheet'));
    $assert(str_contains($script, "event.key === 'Escape'"), 'the sheet closes with Escape');
    $assert(str_contains($script, "document.body.classList.add('sheet-open')"));

    $assert(!str_contains($front, 'class="site-nav"'), 'the public page must not render a top bar');
    $assert(str_contains($front, 'class="bubble bubble--brand"'));
    $assert(str_contains($front, 'data-bubble-menu'));
    $assert(str_contains($front, 'bubble-sheet__links'));

    foreach (['public/views/recruiter/layout-start.php', 'public/views/admin/layout-start.php'] as $file) {
        $contents = file_get_contents($root . '/' . $file);
        $assert(str_contains($contents, 'class="bubble-top bubble-top--portal"'), "{$file} still renders a top bar");
        $assert(!str_contains($contents, 'class="rec-topbar"'));
        $assert(!str_contains($contents, 'class="admin-topbar"'));
        $assert(str_contains($contents, 'bubble--account'));
        $assert(str_contains($contents, 'bubble--icon'), 'utility actions are bubbles');
    }
});

$test('One shared liquid-glass capsule drives every dock and option group', static function () use ($assert, $liquidGlassStyles, $liquidGlassScript, $adminLayoutEnd, $recruiterLayout, $root): void {
    // Material layers: transmission, smoke, specular cap, masked rim, caustic.
    foreach (['.lg-smoke', '.lg-specular', '.lg-rim', '.lg-caustic', '.lg-glint'] as $layer) {
        $assert(str_contains($liquidGlassStyles, $layer), "missing material layer {$layer}");
    }
    $assert(str_contains($liquidGlassStyles, 'mask-composite: exclude'));
    $assert(str_contains($liquidGlassStyles, 'backdrop-filter: blur(20px) saturate(200%)'), 'the pill is a blurred lens, not a flat tint');
    $assert(str_contains($liquidGlassStyles, 'backdrop-filter: blur(30px) saturate(195%)'), 'the capsule blurs the page behind it');
    $assert(str_contains($liquidGlassStyles, '--lg-shell-ring-top'), 'the capsule carries a meniscus ring');
    $assert(str_contains($liquidGlassStyles, '--lg-shell-bright'), 'the glass lifts what is behind it instead of greying it out');
    $assert(str_contains($liquidGlassStyles, 'border-radius: 999px'), 'the dock is an elongated glass capsule');
    $assert(str_contains($liquidGlassStyles, 'mix-blend-mode: screen'), 'highlights are additive light');
    $assert(str_contains($liquidGlassStyles, 'filter: url(#lg-refract)'), 'a small displacement filter adds refraction');
    $assert(str_contains($liquidGlassStyles, '--lg-slosh'));
    $assert(str_contains($liquidGlassStyles, '@supports not ((backdrop-filter: blur(1px))'));

    // Neutral glass: no warm or orange tint in the capsule itself.
    $assert(!preg_match('/\.lg-[a-z]+[^{]*\{[^}]*#(?:[fF][0-9a-fA-F]{5}|[eE][0-9a-fA-F]{5})/s', $liquidGlassStyles), 'the capsule must not carry an orange fill');

    // One engine, shared by all portals.
    $assert(str_contains($liquidGlassScript, 'window.LiquidGlass'));
    $assert(str_contains($liquidGlassScript, 'MOVE_SPRING'));
    $assert(str_contains($liquidGlassScript, 'DRAG_SPRING'));
    $assert(str_contains($liquidGlassScript, 'const STEP = 1 / 240'));
    $assert(str_contains($liquidGlassScript, "window.addEventListener('pointermove'"), 'the press is tracked off the dock so a flick is never lost');
    $assert(!preg_match('/addEventListener\(\'pointerdown\'[\s\S]{0,600}setPointerCapture/s', $liquidGlassScript), 'capturing on pointerdown retargets the click away from the link');
    $assert(str_contains($liquidGlassScript, 'document.elementFromPoint'), 'a retargeted click still resolves the tab under the pointer');
    $assert(str_contains($liquidGlassScript, 'is-preview'));
    $assert(str_contains($liquidGlassScript, 'suppressClickUntil'));
    $assert(str_contains($liquidGlassScript, 'ArrowRight'));
    $assert(str_contains($liquidGlassScript, 'sessionStorage'));
    $assert(str_contains($liquidGlassScript, 'prefers-reduced-motion'));
    $assert(!str_contains($liquidGlassScript, 'setInterval'));
    $assert(str_contains($liquidGlassScript, "layout = 'dock'"));
    $assert(str_contains($liquidGlassScript, 'data-glass-group'));

    // Compact, one-tab-wide geometry rather than the full bar.
    $assert(str_contains($liquidGlassScript, 'metrics[0].width - insetX'));
    $assert(str_contains($liquidGlassScript, 'Math.max(isGroup ? 30 : 28'), 'the pill floor fits the narrowest responsive tab');
    $assert(str_contains($liquidGlassScript, 'Math.min(isGroup ? 9 : 18, insetX)'), 'the stretch budget never exceeds the tab inset');
    $assert(str_contains($liquidGlassScript, 'scale3d('), 'the pill stretches with a transform, not by resizing the box');
    $assert(!preg_match('/indicator\.style\.width\s*=\s*\$\{state\.width/', $liquidGlassScript), 'no layout write per spring frame');
    $assert(str_contains($liquidGlassScript, 'drag.velocity'), 'a flick carries into the settle');
    $assert(str_contains($liquidGlassScript, 'persist(activeIndex)'), 'the rendered tab becomes the next hand-off point');
    $assert(str_contains($liquidGlassScript, 'if (originMetric)'), 'a dock that mounts before layout must not throw');
    $assert(str_contains($liquidGlassScript, 'const handled = !emit('), 'a canceled activate event keeps routing in the page');
    $assert(!preg_match('/\.lg-indicator\s*\{[^}]*\bwidth:\s*100%/s', $liquidGlassStyles), 'the capsule must never span the whole bar');

    // Every portal dock is wired to the same component.
    foreach ([$adminLayoutEnd, $recruiterLayout] as $layout) {
        $assert(str_contains($layout, 'data-glass-dock'));
        $assert(str_contains($layout, 'class="lg-indicator"'));
    }
    $index = file_get_contents($root . '/public/index.php');
    $studentDock = file_get_contents($root . '/public/views/partials/student-dock-markup.php');
    $assert(str_contains($index, 'student-dock-markup.php'), 'index.php renders the shared student dock');
    $assert(str_contains($studentDock, 'data-glass-dock'));
    $adminNav = file_get_contents($root . '/public/assets/js/admin-nav.js');
    $assert(str_contains($adminNav, 'LiquidGlass.refresh(content)'), 'filter groups are remounted after an in-place section swap');
    $assert(str_contains($index, 'assets/js/liquid-glass.js'));

    // Segmented option groups use the same material.
    foreach (['jobs', 'placements', 'reports'] as $view) {
        $markup = file_get_contents($root . '/public/views/admin/' . $view . '.php');
        $assert(str_contains($markup, 'data-glass-group'), "{$view} filters are not a glass group");
        $assert(str_contains($markup, 'lg-option'));
    }
});

$test('Header chips, dock labels and icons stay legible on the glass', static function () use ($assert, $root): void {
    $styles = file_get_contents($root . '/public/assets/css/liquid-glass.css');
    $assert(str_contains($styles, '.lg-option.is-active'));
    $assert(str_contains($styles, '.lg-option:focus-visible'), 'focus stays visible on the glass');
    $assert(str_contains($styles, '.lg-option[disabled]'));
    $assert(str_contains($styles, '.lg-option.is-active { color: var(--lg-ink); }'), 'the selected label uses full-contrast ink');
    $assert(str_contains($styles, '--lg-ink: #24201d'), 'light glass labels are ink on white');
    $assert(str_contains($styles, '--lg-ink: #f2f3f5') || str_contains($styles, '--lg-ink: #f3f4f6'), 'dark glass labels are soft white');
    $assert(str_contains($styles, 'isolation: isolate'), 'blend layers are contained per capsule');
});

$test('Page shifts use view transitions and frame-rate independent motion', static function () use ($assert, $root): void {
    $theme = file_get_contents($root . '/public/assets/css/theme.css');
    $assert(str_contains($theme, '@view-transition { navigation: auto; }'));
    $assert(str_contains($theme, '::view-transition-new(root)'));
    $assert(str_contains($theme, '--sv-ease: cubic-bezier(.22, 1, .36, 1)'));
    $assert(str_contains($theme, 'sv-page-in'));
    $assert(str_contains($theme, 'prefers-reduced-motion'));
    $assert(str_contains($theme, 'body.sheet-open { overflow: hidden; }'));
});

$test('Every portal starts flush with the viewport edge', static function () use ($assert, $root): void {
    $theme = file_get_contents($root . '/public/assets/css/theme.css');
    $app = file_get_contents($root . '/public/assets/css/app.css');
    $glass = file_get_contents($root . '/public/assets/css/liquid-glass.css');

    // The strip was a collapsed top margin on the floating chrome, which let the
    // bare canvas show above the body's own gradient.
    $assert(str_contains($theme, 'display: flow-root'), 'body contains its first child margins');
    $assert(str_contains($theme, '#main-content.is-entering-forward'), 'the student canvas has a directed entrance');
    $assert(str_contains($theme, 'body > main:not(#main-content)'), 'the blanket page-in animation no longer out-ranks the swap states');
    $assert(preg_match('/\.bubble-top \{[^}]*margin: 0 auto 4px;/s', $theme) === 1, 'the floating chrome uses padding, not a top margin');
    $assert(str_contains($theme, 'html, body { margin: 0; padding: 0; }'));
    $assert(str_contains($app, 'background-color: var(--bg)'), 'the body paints its own top edge');
    $assert(!preg_match('/html\[data-theme="dark"\] \{\s*background: var\(--sv-bg-deep\)/', $theme), 'canvas and body share one top colour');
    $assert(str_contains($glass, 'isolation: isolate'), 'dock shells do not leak their blend layers');
    $assert(!preg_match('/html\[data-theme="light"\] body\.admin-portal \.admin-dock\s*\{/s', $theme), 'the light theme leaves the dock material alone');
});

$test('LogicBloom is the only palette, and it stays light', static function () use ($assert, $root): void {
    foreach (['app.css', 'recruiter.css', 'admin.css', 'homepage.css', 'logicbloom.css'] as $file) {
        $css = file_get_contents($root . '/public/assets/css/' . $file);
        $assert(!preg_match('/(?:#0[4-9][0-9a-f]{2}2[0-9a-f]|rgba\(\s*(?:46, 156, 150|58, 168, 158|84, 168, 147))/i', $css), "{$file} still paints a teal background");
    }
    $ice = file_get_contents($root . '/public/assets/css/logicbloom.css');
    // The palette is declared once and re-points the project's own tokens.
    $assert(str_contains($ice, '--lb-accent: #2f7291'), 'the slate-teal accent is the specified one');
    $assert(str_contains($ice, '--sv-orange: var(--lb-accent)'), 'theme.css tokens are re-pointed');
    $assert(str_contains($ice, '--mint: var(--lb-accent)'), 'the legacy app tokens are re-pointed at the same accent');
    $assert(str_contains($ice, '--rec-mint: var(--lb-accent)'), 'the recruiter portal follows the same accent');
    $assert(str_contains($ice, '--adm-mint: var(--lb-accent)'), 'the admin portal follows the same accent');
    // Light only: both attribute states get the same values.
    $assert((bool) preg_match('/html\[data-theme="light"\],\s*html\[data-theme="dark"\]\s*\{/s', $ice), 'the palette ignores the stored theme');
    $assert(substr_count($ice, '--lb-ink: #19394c') === 1, 'ink is declared once');
    // No pure black and no saturated blue game-pad palette.
    $assert(!preg_match('/#000(?:000)?\b/i', $ice), 'no pure black token');
    $assert(!str_contains($ice, '#6366f1'), 'no indigo');
    $assert(!str_contains($ice, '#0ea5e9'), 'no sky blue accent');
    // Self-hosted type only.
    $assert(str_contains($ice, '../fonts/inter-latin-400-normal.woff2'), 'Inter is self-hosted');
    $assert(!preg_match('#(?:fonts\.googleapis|fonts\.gstatic|cdn\.|unpkg|jsdelivr)#i', $ice), 'no third-party asset host');
    foreach (['inter-latin-400-normal.woff2', 'inter-latin-500-normal.woff2', 'inter-latin-600-normal.woff2', 'inter-latin-700-normal.woff2', 'inter-latin-800-normal.woff2', 'Inter-OFL.txt'] as $asset) {
        $assert(is_file($root . '/public/assets/fonts/' . $asset), "assets/fonts/{$asset} is missing");
    }
});

$test('Every themed page loads LogicBloom last and can survive without it', static function () use ($assert, $root): void {
    $heads = [
        'public/index.php',
        'public/views/partials/studio-start.php',
        'public/views/recruiter/layout-start.php',
        'public/views/admin/layout-start.php',
    ];
    foreach ($heads as $file) {
        $markup = file_get_contents($root . '/' . $file);
        $assert(str_contains($markup, 'assets/css/logicbloom.css'), "{$file} does not load the theme");
        $assert(str_contains($markup, 'name="theme-color" content="#edf8fc"'), "{$file} keeps the warm theme colour");
        $lastCss = strrpos($markup, 'assets/css/');
        $themeAt = strpos($markup, 'assets/css/logicbloom.css');
        $assert($themeAt !== false && $themeAt === $lastCss, "{$file} must load logicbloom.css after every other stylesheet");
    }
    foreach (['public/index.php', 'public/views/partials/studio-end.php', 'public/views/recruiter/layout-end.php', 'public/views/admin/layout-end.php'] as $file) {
        $assert(str_contains(file_get_contents($root . '/' . $file), 'theme-guard.js'), "{$file} must ship the theme guard");
    }
    $guard = file_get_contents($root . '/public/assets/js/theme-guard.js');
    $assert(str_contains($guard, "'--lb-accent'"), 'the guard probes a token the theme publishes');
    $assert(str_contains($guard, 'dock.style.display = '), 'a dock without its stylesheet is hidden');
    $assert(str_contains($guard, 'data-theme-guard'), 'the guard leaves evidence on the element it hid');
});

$test('Text meets WCAG AA contrast on the ice palette', static function () use ($assert, $root): void {
    $luminance = static function (string $hex): float {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        $channels = [];
        foreach ([0, 2, 4] as $offset) {
            $value = hexdec(substr($hex, $offset, 2)) / 255;
            $channels[] = $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }
        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    };
    $ratio = static function (string $a, string $b) use ($luminance): float {
        $la = $luminance($a);
        $lb = $luminance($b);
        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    };

    // Backgrounds the text actually sits on.
    $surface = '#ffffff';
    $canvas = '#edf8fc';
    $heroDeep = '#c8e5f1'; // darkest stop of the landing hero gradient
    $pairs = [
        ['body text on a card', '#19394c', $surface, 4.5],
        ['body text on the canvas', '#19394c', $canvas, 4.5],
        ['paragraph copy on a card', '#405f70', $surface, 4.5],
        ['paragraph copy on the hero gradient', '#405f70', $heroDeep, 4.5],
        ['muted label on a card', '#446575', $surface, 4.5],
        ['muted label on the hero gradient', '#446575', $heroDeep, 4.5],
        ['eyebrow on the canvas', '#2f6680', $canvas, 4.5],
        ['eyebrow on the deep canvas', '#2f6680', '#e4f2f8', 4.5],
        ['eyebrow on the hero gradient', '#2f6680', $heroDeep, 4.5],
        ['eyebrow on a card', '#2f6680', $surface, 4.5],
        ['body text on the deep canvas', '#405f70', '#e4f2f8', 4.5],
        ['muted label on the deep canvas', '#446575', '#e4f2f8', 4.5],
        ['headings on a card', '#1a3b4d', $surface, 4.5],
        ['primary button label', '#ffffff', '#2f7291', 4.5],
        ['link on a card', '#23647f', $surface, 4.5],
        ['link on the hero gradient', '#23647f', $heroDeep, 4.5],
        ['active dock label', '#075985', $surface, 4.5],
        ['danger message', '#991b1b', '#fef2f2', 4.5],
        ['success message', '#11624b', '#e8f7f0', 4.5],
        ['info message', '#075985', '#e0f2fe', 4.5],
        // Non-text graphics need 3:1, not 4.5:1.
        ['card icon on its tile', '#2f7291', '#e4f4fa', 3.0],
        ['status dot on a card', '#1f8360', $surface, 3.0],
        ['status dot on the hero gradient', '#1f8360', $heroDeep, 3.0],
        ['focus ring on the canvas', '#0284c7', $canvas, 3.0],
    ];
    foreach ($pairs as [$label, $ink, $paper, $minimum]) {
        $value = round($ratio($ink, $paper), 2);
        $assert($value >= $minimum, "{$label} is {$value}:1, below {$minimum}:1");
    }
});

$test('The theme is self-sufficient, warm-free and request-free', static function () use ($assert, $root): void {
    $theme = file_get_contents($root . '/public/assets/css/logicbloom.css');
    $home = file_get_contents($root . '/public/assets/css/homepage.css');

    // No warm palette survives in the ice theme, and nothing is fetched off-site.
    foreach (['#f2c8a4', '#f7dcc6', '#fff7f0', '#c96f2d', '#0b0c0e', '#090a0c'] as $warm) {
        $assert(stripos($theme, $warm) === false, "logicbloom.css still contains the old colour {$warm}");
    }
    foreach (['logicbloom' => $theme, 'homepage' => $home] as $name => $css) {
        $assert(!preg_match('#https?://#i', $css), "{$name}.css makes no external request");
        $assert(!str_contains($css, '100vw'), "{$name}.css avoids 100vw");
        $assert(!preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $css), "{$name}.css uses no emoji");
    }

    // Every custom property the landing page reads is actually declared.
    preg_match_all('/(--lb-[a-z0-9-]+)\s*:/', $theme, $declared);
    preg_match_all('/var\((--lb-[a-z0-9-]+)/', $home . $theme, $used);
    $missing = array_diff(array_unique($used[1]), array_unique($declared[1]));
    $assert($missing === [], 'undeclared theme tokens: ' . implode(', ', $missing));

    // !important is confined to the reduced-motion block.
    $outside = preg_replace('/@media \(prefers-reduced-motion: reduce\).*?\n}/s', '', $theme);
    $assert(substr_count($outside, '!important') === 1, 'the only !important outside reduced-motion is the comment that explains it');

    // The fonts are local, and the licence travels with them.
    foreach (['inter-latin-400-normal.woff2', 'inter-latin-500-normal.woff2', 'inter-latin-600-normal.woff2', 'inter-latin-700-normal.woff2', 'inter-latin-800-normal.woff2', 'Inter-OFL.txt'] as $file) {
        $assert(is_file($root . '/public/assets/fonts/' . $file), "public/assets/fonts/{$file} is self-hosted");
    }
    $assert(substr_count($theme, '@font-face') === 5, 'logicbloom.css declares the five Inter weights it uses');
    $assert(str_contains($theme, 'font-display: swap'), 'Inter swaps in rather than blocking paint');

    // Component rules win by prefix, not by force.
    $scoped = preg_match_all('/^html\[data-theme\]/m', $theme);
    $assert($scoped >= 100, "components are scoped to the theme attribute ({$scoped} scoped rules)");
    $assert(str_contains($theme, 'prefers-reduced-motion: reduce'), 'motion can be switched off');
});

$test('One asset version busts every cached stylesheet and script', static function () use ($assert, $root): void {
    $bootstrap = file_get_contents($root . '/bootstrap/app.php');
    $assert(str_contains($bootstrap, 'function asset_version()'), 'assets share one version token');
    $assert(str_contains($bootstrap, "glob(dirname(__DIR__) . '/public/assets/' . \$kind . '/*.' . \$kind)"), 'the token follows the newest css and js file');
    foreach (['public/index.php', 'public/admin.php', 'public/recruiter.php', 'public/views/partials/studio-start.php', 'public/views/partials/studio-end.php'] as $file) {
        $markup = file_get_contents($root . '/' . $file);
        $assert(str_contains($markup, 'asset_version()'), "{$file} uses the shared token");
        $assert(!str_contains($markup, "filemtime(__DIR__ . '/assets/"), "{$file} no longer pins the token to one file");
    }
});

$test('The capsule geometry is viewport-driven, not a fixed desktop width', static function () use ($assert, $root): void {
    $student = file_get_contents($root . '/public/assets/css/water-dock.css');
    $recruiter = file_get_contents($root . '/public/assets/css/recruiter.css');
    $admin = file_get_contents($root . '/public/assets/css/admin.css');
    $glass = file_get_contents($root . '/public/assets/css/liquid-glass.css');

    $assert(str_contains($glass, '--dock-gutter: clamp('), 'the side gutter scales with the viewport');
    $assert(str_contains($glass, '--dock-tab-height: clamp('), 'tab height scales with the viewport');
    $assert(str_contains($glass, '--dock-icon: clamp('), 'icons scale with the viewport');
    $assert(str_contains($glass, '--dock-clearance: calc(var(--dock-shell-height)'), 'clearance is derived from the real dock height');

    foreach (['student' => [$student, '820px'], 'recruiter' => [$recruiter, '820px'], 'admin' => [$admin, '960px']] as $name => [$css, $max]) {
        $assert(str_contains($css, 'width: min(var(--dock-max'), "the {$name} capsule shrinks with the window");
        $assert(str_contains($css, '--dock-max: ' . $max), "the {$name} capsule keeps its wide desktop ceiling");
        $selector = ['student' => '.sv-dock', 'recruiter' => '.rec-bottom-nav', 'admin' => '.admin-dock'][$name];
        $assert(preg_match('/' . preg_quote($selector, '/') . '\s*\{([^}]*)\}/', $css, $block) === 1, "the {$name} dock rule is present");
        $assert(!preg_match('/(?<![a-z-])(?:min-)?width:\s*\d+px/', $block[1]), "the {$name} dock rule sets no hard-coded width");
    }
    $assert(str_contains($student, 'min-height: var(--dock-tab-height)'), 'tabs keep a usable touch target');
    $assert(str_contains($recruiter, 'min-height: var(--dock-tab-height)'));
    $assert(str_contains($admin, 'min-height: var(--dock-tab-height)'));

    // A fixed per-tab floor is what stopped the narrow docks from shrinking.
    $assert(!preg_match('/\.sv-dock-link\s*\{[^}]*min-width:\s*\d+px/', $student), 'student tabs have no fixed floor');
    $assert(!preg_match('/\.rec-nav-link\s*\{[^}]*min-width:\s*\d+px/', $recruiter), 'recruiter tabs have no fixed floor');
    $assert(!preg_match('/\.admin-dock-link\s*\{[^}]*min-width:\s*\d+px/', $admin), 'admin tabs have no fixed floor');
});

$test('No portal footer can end up underneath the floating dock', static function () use ($assert, $root): void {
    $student = file_get_contents($root . '/public/assets/css/water-dock.css');
    $recruiter = file_get_contents($root . '/public/assets/css/recruiter.css');
    $admin = file_get_contents($root . '/public/assets/css/admin.css');

    // A negative top margin pulled the footer into the dock's band; the footer
    // now reserves the dock's own measured clearance instead.
    foreach (['recruiter' => $recruiter, 'admin' => $admin] as $name => $css) {
        $assert(!preg_match('/\.(rec|admin)-footer\s*\{[^}]*margin(?:-top)?:\s*-\d+px/', $css), "the {$name} footer is no longer pulled up under the dock");
        $assert(str_contains($css, 'var(--dock-clearance, 118px)'), "the {$name} footer reserves the dock clearance");
    }
    $assert(str_contains($student, '.dashboard { padding-bottom: var(--dock-clearance, 118px); }'), 'the student canvas reserves it too');
    $assert(str_contains($student, '.site-footer { padding-bottom: calc(var(--dock-bottom) + 26px); }'), 'and the student footer keeps its own spacing');
});

$test('The dock switches to a compact layout before labels can clip', static function () use ($assert, $root): void {
    $student = file_get_contents($root . '/public/assets/css/water-dock.css');
    $admin = file_get_contents($root . '/public/assets/css/admin.css');
    $engine = file_get_contents($root . '/public/assets/js/liquid-glass.js');
    $assert(str_contains($student, '@media (max-width: 380px)'), 'the five-tab dock drops labels on the narrowest phones');
    $assert(str_contains($student, 'clip-path: inset(50%)'), 'the label stays available to screen readers');
    $assert(str_contains($admin, '.admin-dock-label'), 'the eight-tab rail compacts earlier than the five-tab docks');
    $assert(str_contains($admin, 'clip-path: inset(50%)'));
    $assert(str_contains($student, '@media (max-height: 460px)'), 'a short split pane gets a shorter capsule');
    $assert(str_contains($engine, 'window.visualViewport'), 'split-screen changes the visual viewport, not just the window');
    $assert(str_contains($engine, 'resizeFrame = window.requestAnimationFrame(applyResize)'), 'continuous resizing is coalesced to one measurement per frame');
    $assert(str_contains($engine, 'state.baseWidth = Math.max(isGroup ? 30 : 28'), 'the pill stays inside one tab at every width');
});

/*
 * ---------------------------------------------------------------------------
 * Intelligence layer (phases 1–4).
 *
 * These services are deterministic and dependency-free, so they can be tested
 * here without a database or a browser. Each test asserts a behaviour the UI
 * depends on — a null instead of a fabricated 0, a band instead of a decimal,
 * a claim that stays a claim, a flag that stays a review prompt.
 * ---------------------------------------------------------------------------
 */

$test('Scoring weights come from one config and stay normalised', static function () use ($assert): void {
    $model = new ScoringModel();
    $weights = $model->weights();
    $assert(abs(array_sum($weights) - 1.0) < 0.0001, 'default weights must sum to 1');
    $assert($model->weights() === $weights, 'resolved once, not per component');
    $overridden = new ScoringModel([], ['scoring.weights.skills' => '0.2']);
    $assert(abs(array_sum($overridden->weights()) - 1.0) < 0.0001, 'an override is renormalised');
    $assert($overridden->weights()['skills'] < $weights['skills'], 'the override is applied');
    $ignored = new ScoringModel([], ['scoring.weights.skills' => 'not-a-number']);
    $assert($ignored->weights()['skills'] === $weights['skills'], 'a malformed override is ignored');
    $assert($model->band(85) !== $model->band(35), 'bands differ at the extremes');
    $assert($model->demonstratedThreshold() === 0.6, 'a claim is not a demonstration');
});

$test('Evidence separates a claim from a demonstrated skill', static function () use ($assert): void {
    $model = new ScoringModel();
    $service = new EvidenceService($model);
    $map = $service->build(
        [['name' => 'PHP', 'proficiency' => 'advanced', 'verified' => 0], ['name' => 'SQL', 'proficiency' => 'beginner', 'verified' => 0]],
        [['title' => 'Portal', 'description' => 'Built a portal', 'skills_json' => '["PHP"]']],
        [],
        []
    );
    $assert(isset($map['php']), 'a claimed skill is present in the map');
    $assert($map['php']['value'] >= $model->demonstratedThreshold(), 'a project raises PHP above the threshold');
    $assert($map['sql']['value'] < $model->demonstratedThreshold(), 'SQL without evidence stays a claim');
    $demonstrated = $service->demonstrated($map);
    $assert(count($demonstrated) === 1 && $demonstrated[0]['level'] !== 'claimed', 'only PHP counts as demonstrated');
    $assert($service->normalise('  Node.JS ') === 'node.js', 'skill keys are normalised for lookup');
    $assert(in_array('sql', $service->skillsFromText('Wrote SQL queries and PHP scripts'), true), 'skills can be read from free text');
});

$test('Skill gaps rank mandatory gaps above preferred ones and drop the demonstrated', static function () use ($assert): void {
    $model = new ScoringModel();
    $evidenceService = new EvidenceService($model);
    $evidence = $evidenceService->build(
        [['name' => 'PHP', 'proficiency' => 'advanced', 'verified' => 0], ['name' => 'SQL', 'proficiency' => 'beginner', 'verified' => 0]],
        [['title' => 'Portal', 'skills_json' => '["PHP"]']],
        [],
        []
    );
    $rows = (new SkillGapService())->prioritise(['PHP', 'Docker'], ['Kubernetes'], $evidence, $evidenceService, $model);
    $skills = array_column($rows, 'skill');
    $assert(in_array('Docker', $skills, true), 'a missing required skill is listed');
    $assert(!in_array('PHP', $skills, true), 'a demonstrated skill is not listed as a gap');
    $assert($rows[0]['type'] === 'required', 'required gaps come first');
    $assert($rows[0]['priority'] === 'High', 'one required gap is high priority');
    $assert(str_contains($rows[0]['why'], 'required'), 'the reason names the requirement type');
});

$test('A six-week roadmap has real tasks for every week', static function () use ($assert): void {
    $plan = (new RoadmapService())->plan(['Docker', 'SQL'], ['weeks' => 6, 'target' => 'Backend Engineer']);
    $assert(count($plan) === 6, 'six weeks are planned');
    foreach ($plan as $week) {
        $assert($week['tasks'] !== [], 'every week has at least one task');
        foreach ($week['tasks'] as $task) {
            $assert(in_array($task['type'], ['technical', 'practice', 'interview', 'portfolio', 'resume'], true), 'task types are known');
            $assert($task['hours'] > 0, 'each task carries an estimate');
        }
    }
    $assert(str_contains($plan[5]['tasks'][0]['title'], 'Tailor your resume') || $plan[5]['theme'] === 'Interview ready', 'the last week closes the loop');
    $assert((new RoadmapService())->resourceFor('docker') !== '', 'a known skill has a study resource');
});

$test('The what-if simulator changes nothing and says it is a simulation', static function () use ($assert): void {
    $model = new ScoringModel();
    $service = new WhatIfService(new CareerFitService($model), new EligibilityService(), $model);
    $job = [
        'id' => 1, 'title' => 'Backend', 'company_name' => 'ACME', 'status' => 'published',
        'min_cgpa' => 7.0, 'required_skills' => ['PHP', 'Docker'], 'preferred_skills' => [],
    ];
    $state = ['profile' => ['cgpa' => 7.4, 'branch' => 'CSE'], 'skills' => ['PHP'], 'evidence' => [], 'jobs' => [$job]];
    $result = $service->simulate($state, ['add_skills' => ['Docker']]);
    $assert($result['after_total'] > $result['before_total'], 'adding a required skill raises the estimate');
    $assert($result['simulated_skills'] === ['Docker'], 'only the imagined skills are reported');
    $assert(str_contains($result['notice'], 'Simulated only'), 'the result is labelled a simulation');
    $assert(str_contains($result['notice'], 'does not guarantee'), 'no outcome is promised');
    // A CGPA change can move eligibility, which is evaluated separately.
    $cgpa = $service->simulate($state, ['cgpa' => 6.0]);
    $assert($cgpa['rows'] !== [] && $cgpa['rows'][0]['eligibility_changed'] === true, 'a lower CGPA flips hard eligibility');
});

$test('Readiness reports null categories instead of inventing zeros', static function () use ($assert): void {
    $model = new ScoringModel();
    $service = new ReadinessService($model);
    $empty = $service->score(['profile' => [], 'resume' => null, 'skills' => [], 'evidence' => [], 'projects' => [], 'certifications' => [], 'assessments' => [], 'practice' => []]);
    $assert($empty['total'] !== null, 'a profile score still exists');
    $keys = array_column($empty['categories'], 'key');
    $assert(in_array('assessments', $keys, true), 'assessment category is listed');
    foreach ($empty['categories'] as $category) {
        if ($category['key'] === 'assessments' || $category['key'] === 'interview') {
            $assert($category['score'] === null, $category['key'] . ' has no evidence yet');
        }
        $assert($category['next'] !== '', 'every category suggests a next step');
    }
    $assert(str_contains($empty['notice'], 'not a placement prediction'), 'readiness is not a promise');
});

$test('Resume analysis reads structure and never invents a metric', static function () use ($assert): void {
    $model = new ScoringModel();
    $service = new ResumeAnalysisService(new EvidenceService($model));
    $text = "Priya Sharma\npriya@example.com | +91 98765 43210\n\nSkills\nPHP, MySQL, Git\n\nProjects\n- Built a booking portal for 120 students.\n\nEducation\nB.Tech CSE, 2027";
    $fields = $service->extract($text);
    $assert(in_array('php', $fields['skills'], true), 'a listed skill is recognised');
    $assert($fields['projects'] !== [], 'the projects section is captured');
    $assert(in_array('skills', $fields['sections_found'], true), 'headings are tracked');
    $comparison = $service->compare($text, ['required_skills' => ['PHP', 'Docker'], 'preferred_skills' => ['Git']]);
    $assert($comparison['matched'] === ['PHP'], 'a word-boundary match, not a substring');
    $assert($comparison['missing'] === ['Docker'], 'a skill absent from the resume is missing');
    $notes = $service->review($text);
    $assert($notes !== [], 'review returns observations');
    foreach ($notes as $note) {
        $assert(isset($note['level'], $note['message']), 'each observation is labelled');
    }
    $bullet = $service->improveBullet('worked on the college website');
    $assert(str_starts_with($bullet['suggestion'], 'Built'), 'a weak verb is replaced');
    $assert(!preg_match('/\d{2,}/', $bullet['suggestion']), 'no number is invented');
});

$test('A pasted job description becomes fields, with the gaps named', static function () use ($assert): void {
    $draft = (new JobDescriptionService(new SkillListService()))->draft(
        "Job title: Backend Engineer\nLocation: Mohali\nEmployment type: Full-time\nMinimum CGPA: 7.0\n"
        . "Required skills: PHP, MySQL\nPreferred skills: Docker\nResponsibilities:\n- Build APIs\n- Review code"
    );
    $assert($draft['title'] === 'Backend Engineer', 'title is extracted');
    $assert($draft['min_cgpa'] === 7.0, 'the CGPA bar is extracted');
    $assert(in_array('PHP', $draft['required_skills'], true), 'required skills are parsed');
    $assert(in_array('Docker', $draft['preferred_skills'], true), 'preferred skills are parsed');
    $assert($draft['responsibilities'] !== [], 'responsibilities are captured');
    $assert($draft['missing'] === [], 'nothing is reported missing when everything is present');
    $sparse = (new JobDescriptionService(new SkillListService()))->draft('We need someone good.');
    $assert($sparse['missing'] !== [], 'unknown fields are listed rather than guessed');
});

$test('The interview kit and mock coach use a rubric, not a personality test', static function () use ($assert): void {
    $kit = (new InterviewKitService())->build(['PHP'], ['Docker'], 'Backend');
    $assert($kit !== [], 'a kit is generated from the requirements');
    foreach ($kit as $question) {
        $assert(in_array($question['type'], ['technical', 'coding', 'aptitude', 'behavioural', 'role'], true), 'question types are known');
        $assert(count($question['rubric']) >= 1, 'every question carries a rubric');
        $assert(!preg_match('/\b(emotion|personality|face|voice|accent)\b/i', $question['prompt']), 'no invasive question types');
    }
    $questions = (new MockInterviewService(new InterviewKitService()))->questions(['PHP'], [], 'Backend');
    $assert(count($questions) <= 4 && $questions !== [], 'a session stays short');
    $feedback = (new MockInterviewService(new InterviewKitService()))->evaluate(
        'First I profiled the slow query, then added an index because the table scan was the bottleneck; the result was a 40% drop in response time.',
        $questions[0]
    );
    $assert(isset($feedback['criteria']['structure']), 'the rubric is explicit');
    $assert($feedback['score'] >= 0 && $feedback['score'] <= 100, 'the rubric is a percentage of criteria met');
    $assert(str_contains($feedback['feedback'], 'Guidance') || $feedback['score'] === 100, 'the wording is guidance, not a verdict');
    $assert($feedback['improved'] !== '', 'an improved skeleton is offered');
});

$test('Assessment levels are withheld when there is not enough evidence', static function () use ($assert): void {
    $service = new AssessmentService(new ScoringModel());
    $assert($service->level(100, 4) === 'Not enough evidence', 'four questions prove nothing');
    $assert($service->level(90, 5) === 'Advanced');
    $assert($service->level(70, 5) === 'Intermediate');
    $assert($service->level(45, 5) === 'Developing');
    $assert($service->level(20, 5) === 'Beginner');
    $scored = $service->score(
        [['id' => 1, 'correct' => 1, 'points' => 2], ['id' => 2, 'correct' => 0, 'points' => 1]],
        [1 => 1, 2 => 1]
    );
    $assert($scored['percent'] === 66.7, (string) $scored['percent']);
    $assert($scored['review'][1]['is_correct'] === false, 'a wrong answer is recorded, not hidden');
    $assert($service->isDemonstrated(60.0) && !$service->isDemonstrated(59.9), 'the 60% evidence line is explicit');
});

$test('Institution insights describe and never forecast', static function () use ($assert): void {
    $service = new InstitutionService();
    $short = $service->trend([['period' => '2026-01', 'count' => 4], ['period' => '2026-02', 'count' => 6]]);
    $assert($short['status'] === 'insufficient', 'two points are not a trend');
    $assert($short['direction'] === null, 'no direction is inferred');
    $series = [];
    for ($month = 1; $month <= 8; $month++) {
        $series[] = ['period' => '2026-' . str_pad((string) $month, 2, '0', STR_PAD_LEFT), 'count' => $month * 2];
    }
    $long = $service->trend($series);
    $assert($long['status'] === 'descriptive', 'eight points are described');
    $assert(str_contains($long['note'], 'not a forecast'), 'the note refuses to predict');
    $readiness = $service->readinessMap([
        ['branch' => 'CSE', 'readiness' => 70.0], ['branch' => 'CSE', 'readiness' => 50.0], ['branch' => 'ECE', 'readiness' => null],
    ]);
    $assert($readiness[0]['branch'] === 'CSE' && $readiness[0]['average'] === 60.0, 'branches are averaged');
    $assert($readiness[1]['average'] === null && $readiness[1]['measured'] === 0, 'an unmeasured branch shows no average');
    $flags = $service->anomalies([['type' => 'Test', 'detail' => 'Detail', 'record' => 'Job #1']]);
    $assert(str_contains($flags[0]['action'], 'not a finding of wrongdoing'), 'a flag is a review prompt');
    $demand = $service->skillDemand([
        ['skill' => 'PHP', 'type' => 'required', 'job' => '1'],
        ['skill' => 'PHP', 'type' => 'required', 'job' => '2'],
        ['skill' => 'Docker', 'type' => 'preferred', 'job' => '1'],
    ]);
    $assert($demand['skills'][0]['skill'] === 'PHP' && $demand['skills'][0]['required'] === 2, 'demand is counted per role');
    $assert($demand['combinations'][0]['pair'] === 'docker + php', 'co-occurrence is reported');
});

$test('Career graph marks requirement status from real evidence', static function () use ($assert): void {
    $model = new ScoringModel();
    $evidence = (new EvidenceService($model))->build(
        [['name' => 'PHP', 'proficiency' => 'advanced', 'verified' => 0], ['name' => 'SQL', 'proficiency' => 'beginner', 'verified' => 0]],
        [['title' => 'Portal', 'skills_json' => '["PHP"]']],
        [],
        []
    );
    $graph = (new CareerGraphService(new RoadmapService()))->build(
        ['title' => 'Backend', 'company_name' => 'ACME', 'required_skills' => ['PHP', 'Docker'], 'preferred_skills' => ['Redis']],
        $evidence,
        [['title' => 'Portal', 'skills_json' => '["PHP"]']],
        []
    );
    $skills = [];
    foreach ($graph['nodes'] as $node) {
        if ($node['kind'] === 'skill') {
            $skills[$node['label']] = $node['status'];
        }
    }
    $assert($skills['PHP'] === 'demonstrated', 'a project-backed skill is demonstrated');
    $assert($skills['Docker'] === 'missing', 'an unlisted skill is missing');
    $assert($skills['Redis'] === 'missing', 'preferred skills are shown too');
    $assert(str_contains($graph['notice'], 'not a guarantee'), 'the graph is a suggestion');
});

$test('Intelligence pages are student-scoped, CSRF-protected and free of client keys', static function () use ($assert, $root): void {
    foreach (['public/career.php', 'public/resume-review.php', 'public/practice.php'] as $file) {
        $contents = file_get_contents($root . '/' . $file);
        $assert(str_contains($contents, 'Security::verifyCsrf'), "{$file} verifies CSRF");
        $assert(str_contains($contents, 'student_id') || str_contains($contents, 'studentId'), "{$file} scopes queries to the student");
        $assert(!preg_match('/sk-[A-Za-z0-9_-]{16,}/', $contents), "{$file} carries no API key material");
    }
    foreach (['public/views/partials/studio-start.php', 'public/views/partials/studio-end.php', 'public/views/recruiter/ai.php', 'public/views/admin/intelligence.php'] as $view) {
        $contents = file_get_contents($root . '/' . $view);
        $assert(!str_contains($contents, 'AI_API_KEY'), "{$view} must never touch the API key");
    }
    $report = file_get_contents($root . '/public/views/admin/intelligence.php');
    $assert(str_contains($report, 'InstitutionReport') || str_contains($report, '$institutionReport'), 'the admin view reads the read-only report');
    $assert(!str_contains($report, 'student_name'), 'the cohort view never names a student');
    $admin = file_get_contents($root . '/public/admin.php');
    $assert(str_contains($admin, "Security::requireRole(['tpo','admin'])"), 'the intelligence section stays TPO/admin only');
    $recruiter = file_get_contents($root . '/public/views/recruiter/ai.php');
    $assert(str_contains($recruiter, 'never publishes') || str_contains($recruiter, 'does not create a job'), 'the recruiter tools cannot publish');
    foreach (['database/migrations/2026-10-22-ai-intelligence.sql', 'database/migrations/2026-10-22-roadmap-columns.sql', 'database/migrations/2026-10-22-assessment-skill.sql'] as $file) {
        $sql = file_get_contents($root . '/' . $file);
        $assert(!preg_match('/\b(DROP\s+TABLE|TRUNCATE|DELETE\s+FROM)\b/i', $sql), "{$file} is additive only");
    }
});

$test('Career studio pages are linked from the public site and never 404', static function () use ($assert, $root): void {
    $front = file_get_contents($root . '/public/index.php');
    // Bookmarked index.php?page=… links hand off to the standalone scripts.
    foreach (['career' => 'career.php', 'resume-review' => 'resume-review.php', 'practice' => 'practice.php'] as $slug => $target) {
        $assert(str_contains($front, "'{$slug}' => '{$target}'"), "no redirect for {$slug}");
        $assert(file_exists($root . '/public/' . $target), "{$target} does not exist");
        // The menu is the only way into the studio from the portal itself.
        $assert(str_contains($front, 'href="' . $target . '"'), "{$target} is not linked from the menu");
    }
    $assert(str_contains($front, ") === 'GET')"), 'the studio redirect must never swallow a POST body');
    $assert(substr_count($front, 'header(') >= 1, 'the redirect uses a Location header');
});

$test('The application stays runnable on PHP 8.0 and says so when it cannot run', static function () use ($assert, $root): void {
    $scanned = [];
    foreach (['app', 'public', 'scripts', 'bootstrap'] as $folder) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $folder, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $scanned[] = $file->getPathname();
            }
        }
    }
    $assert(count($scanned) > 30, 'the syntax scan should cover the whole application');
    foreach ($scanned as $file) {
        $contents = file_get_contents($file) ?: '';
        $relative = str_replace($root . '/', '', $file);
        // 8.1-only: promoted readonly properties and never-returning functions.
        $assert(!preg_match('/\breadonly\s+(?:[?\\\\A-Za-z_][A-Za-z0-9_\\\\]*\s+)?\$/', $contents), "{$relative} uses a readonly property (PHP 8.1)");
        $assert(!preg_match('/\)\s*:\s*never\b/', $contents), "{$relative} returns never (PHP 8.1)");
        $assert(!preg_match('/\benum\s+[A-Z][A-Za-z0-9_]*\s*(?::|\{)/', $contents), "{$relative} declares an enum (PHP 8.1)");
    }

    $bootstrap = file_get_contents($root . '/bootstrap/app.php');
    $guardAt = strpos($bootstrap, 'PHP_VERSION_ID');
    $firstRequire = strpos($bootstrap, 'require_once');
    $assert($guardAt !== false, 'bootstrap/app.php must check the PHP version');
    $assert($firstRequire !== false && $guardAt < $firstRequire, 'the version guard must run before the first include');
    $assert(str_contains($bootstrap, "'8.0'") || str_contains($bootstrap, '80000'), 'the guard names the minimum version');

    $doctor = file_get_contents($root . '/scripts/doctor.php');
    $assert(str_contains($doctor, 'PHP_VERSION_ID'), 'doctor reports the PHP version');
    $assert(str_contains($doctor, '--no-db'), 'doctor can skip the database');
    $assert(!preg_match('/\b(INSERT\s+INTO|UPDATE\s+[a-z_]+\s+SET|DELETE\s+FROM|DROP\s+TABLE)\b/i', $doctor), 'doctor is read-only');
    $assert(!preg_match('/DB_PASS[^\n]*echo/', $doctor), 'doctor never prints the password');
});

$test('No merge-conflict markers remain in key project files', static function () use ($assert, $root): void {
    foreach (['README.md', 'scripts/create-admin.php', 'tests/run.php'] as $file) {
        $contents = file_get_contents($root . '/' . $file);
        $assert(!preg_match('/^(<<<<<<<|=======|>>>>>>>)/m', $contents), "conflict marker in {$file}");
    }
});

echo "\nResults: {$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
