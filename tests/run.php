<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Services/EligibilityService.php';
require dirname(__DIR__) . '/app/Services/CareerFitService.php';
require dirname(__DIR__) . '/app/Services/SkillGapService.php';
require dirname(__DIR__) . '/app/Services/RecommendationService.php';
require dirname(__DIR__) . '/app/Services/RoadmapService.php';
require dirname(__DIR__) . '/app/Services/AdminImportService.php';

use App\Services\CareerFitService;
use App\Services\EligibilityService;
use App\Services\RecommendationService;
use App\Services\RoadmapService;
use App\Services\SkillGapService;
use App\Services\AdminImportService;

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
    foreach (['public/index.php', 'public/career.php'] as $file) {
        $contents = file_get_contents($root . '/' . $file);
        $assert(str_contains($contents, 'name="action" value="logout"'), "logout action missing in {$file}");
        $assert(str_contains($contents, 'name="_csrf"'), "CSRF token missing in {$file}");
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

$test('Recruiter views provide a responsive liquid-glass dock with a persistent sliding bubble', static function () use ($assert, $recruiterLayout, $recruiterStyles, $recruiterNavScript, $root): void {
    $assert(str_contains($recruiterLayout, 'class="rec-bottom-nav"'));
    $assert(str_contains($recruiterLayout, 'class="rec-nav-indicator"'));
    $assert(str_contains($recruiterLayout, 'aria-label="Recruiter workspace"'));
    $assert(str_contains($recruiterLayout, 'assets/js/recruiter-nav.js?v='));
    $assert(str_contains($recruiterStyles, 'backdrop-filter: blur(30px)'));
    $assert(str_contains($recruiterStyles, '.rec-nav-indicator.is-stretched') || str_contains($recruiterNavScript, 'is-stretched'));
    $assert(!str_contains($recruiterStyles, 'rec-liquid-wobble'));
    $assert(str_contains($recruiterStyles, 'env(safe-area-inset-bottom'));
    $assert(str_contains($recruiterStyles, '@media (prefers-reduced-motion: reduce)'));
    $assert(str_contains($recruiterNavScript, 'sessionStorage'));
    $assert(str_contains($recruiterNavScript, 'translate3d('));
    $assert(str_contains($recruiterNavScript, 'prefers-reduced-motion'));
    foreach (['overview', 'company', 'jobs', 'applicants', 'interviews'] as $view) {
        $assert(is_file($root . '/public/views/recruiter/' . $view . '.php'), "missing recruiter view {$view}");
        $assert(str_contains($recruiterLayout, 'data-rec-section="' . $view . '"'));
    }
});

$test('Recruiter navigation is a gesture-driven liquid dock wired for safe client-side sections', static function () use ($assert, $recruiterLayout, $recruiterLayoutStart, $recruiterStyles, $recruiterNavScript): void {
    $assert(str_contains($recruiterLayoutStart, 'id="recruiter-section-content"'));
    $assert(str_contains($recruiterLayout, 'id="recruiter-nav-status"'));
    $assert(str_contains($recruiterLayout, 'aria-live="polite"'));
    $assert(str_contains($recruiterNavScript, "addEventListener('pointerdown'"));
    $assert(str_contains($recruiterNavScript, "addEventListener('pointermove'"));
    $assert(str_contains($recruiterNavScript, "addEventListener('pointerup'"));
    $assert(str_contains($recruiterNavScript, "addEventListener('pointercancel'"));
    $assert(str_contains($recruiterNavScript, 'setPointerCapture'));
    $assert(str_contains($recruiterNavScript, 'requestAnimationFrame'));
    $assert(!str_contains($recruiterNavScript, 'setInterval'));
    $assert(str_contains($recruiterNavScript, 'suppressClickUntil'));
    $assert(str_contains($recruiterNavScript, 'is-preview'));
    $assert(str_contains($recruiterNavScript, 'window.history.pushState'));
    $assert(str_contains($recruiterNavScript, "'popstate'"));
    $assert(str_contains($recruiterNavScript, "addEventListener('submit'"));
    $assert(str_contains($recruiterNavScript, 'DOMParser'));
    $assert(str_contains($recruiterNavScript, 'prefers-reduced-motion'));
    $assert(str_contains($recruiterNavScript, 'ArrowRight'));
    $assert(str_contains($recruiterStyles, '.rec-page-content.is-pending[data-direction="forward"]'));
    $assert(str_contains($recruiterStyles, '.rec-page-content.is-entering[data-direction="backward"]'));
    $assert(str_contains($recruiterStyles, '.rec-page-content.is-swiping { transition: none; }'));
    $assert(str_contains($recruiterStyles, '.rec-bottom-nav.is-dragging'));
    $assert(str_contains($recruiterStyles, 'touch-action: pan-y'));
    $assert(str_contains($recruiterStyles, '@keyframes rec-section-enter-forward'));
    $assert(str_contains($recruiterStyles, '@keyframes rec-section-enter-backward'));
    $assert(str_contains($recruiterStyles, 'user-select: none'));
});

$test('Recruiter dock renders a dimensional glass capsule driven by a spring', static function () use ($assert, $recruiterLayout, $recruiterStyles, $recruiterNavScript): void {
    // Layered 3D material, not a flat pill.
    foreach (['rec-nav-bubble-liquid', 'rec-nav-bubble-rim', 'rec-nav-bubble-caustic'] as $layer) {
        $assert(str_contains($recruiterLayout, $layer), "missing glass layer {$layer}");
    }
    $assert(str_contains($recruiterStyles, 'border-radius: 999px'));
    $assert(str_contains($recruiterStyles, '.rec-nav-indicator::before'));
    $assert(str_contains($recruiterStyles, '.rec-nav-indicator::after'));
    $assert(str_contains($recruiterStyles, 'mask-composite: exclude'));
    $assert(str_contains($recruiterStyles, '-webkit-mask-composite: xor'));
    $assert(str_contains($recruiterStyles, 'backdrop-filter: blur(12px) saturate(185%)'));
    $assert(str_contains($recruiterStyles, 'box-shadow:'));

    // One tab wide, not the whole bar, and re-sized from the tab box.
    $assert(str_contains($recruiterNavScript, 'bubble.baseWidth'));
    $assert(str_contains($recruiterNavScript, 'getBoundingClientRect'));
    $assert(str_contains($recruiterNavScript, 'narrowest - inset'));
    $assert(!str_contains($recruiterStyles, '.rec-nav-indicator { width: 100%'));

    // Real spring motion with stretch, squash and interior slosh.
    $assert(str_contains($recruiterNavScript, 'MOVE_SPRING'));
    $assert(str_contains($recruiterNavScript, 'DRAG_SPRING'));
    $assert(str_contains($recruiterNavScript, 'FIXED_STEP'));
    $assert(str_contains($recruiterNavScript, '--rec-slosh'));
    $assert(str_contains($recruiterNavScript, '--rec-stretch'));
    $assert(str_contains($recruiterNavScript, 'scaleY('));
    $assert(str_contains($recruiterNavScript, 'bubble.dragging'));
    $assert(str_contains($recruiterNavScript, 'has-sliding-indicator'));
    $assert(!str_contains($recruiterStyles, 'transition: transform .36s'));

    // Degradation + accessibility.
    $assert(str_contains($recruiterStyles, '@supports not ((backdrop-filter: blur(1px))'));
    $assert(str_contains($recruiterStyles, ':not(.has-sliding-indicator) .rec-nav-link.is-active::before'));
    $assert(str_contains($recruiterLayout, 'aria-hidden="true"'));
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

$test('Admin portal has eight routed sections with all views on disk', static function () use ($assert, $adminController, $adminLayoutEnd, $adminLayoutStart, $root): void {
    $sections = ['overview', 'approvals', 'students', 'placements', 'jobs', 'reports', 'comms', 'governance'];
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
    foreach (['admin-dock-liquid', 'admin-dock-rim', 'admin-dock-caustic'] as $layer) {
        $assert(str_contains($adminLayoutEnd, $layer), "missing dock layer {$layer}");
    }
    $assert(str_contains($adminStyles, 'overflow-x: auto'), 'the rail must scroll');
    $assert(str_contains($adminStyles, 'scrollbar-width: none'));
    $assert(str_contains($adminStyles, 'border-radius: 999px'));
    $assert(str_contains($adminStyles, 'mask-composite: exclude'));
    $assert(str_contains($adminStyles, 'backdrop-filter: blur(12px) saturate(185%)'));
    $assert(str_contains($adminStyles, '@media (prefers-reduced-motion: reduce)'));
    $assert(str_contains($adminStyles, '@supports not ((backdrop-filter: blur(1px))'));

    $assert(str_contains($adminNavScript, 'admin-dock-track'));
    $assert(str_contains($adminNavScript, 'scrollLeft'), 'dragging pans the rail');
    $assert(str_contains($adminNavScript, 'MOVE_SPRING'));
    $assert(str_contains($adminNavScript, 'DRAG_SPRING'));
    $assert(str_contains($adminNavScript, 'setPointerCapture'));
    $assert(str_contains($adminNavScript, 'requestAnimationFrame'));
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
    $assert(str_contains($theme, '--sv-bg: #faf7f3'), 'default surface is warm ivory');
    $assert(str_contains($theme, '--sv-orange: #cf5a17'), 'Ember & Slate orange is the brand accent');
    $assert(str_contains($theme, '--sv-bg: #0d1114'), 'dark mode is a slate pool, not navy');
    $assert(str_contains($theme, '--sv-drop:'), 'the dock droplet has a watery fill per theme');
    $assert(str_contains($theme, 'html[data-theme="light"] body.recruiter-portal'));
    $assert(str_contains($theme, 'html[data-theme="light"] body.admin-portal'));
    $assert(str_contains($theme, 'html[data-theme="dark"] body.recruiter-portal'));

    $script = file_get_contents($root . '/public/assets/js/theme.js');
    $assert(str_contains($script, "localStorage.setItem(STORAGE_KEY, next)"));
    $assert(str_contains($script, "data-theme-toggle"));
    $assert(str_contains($script, "root.dataset.theme"));
    $assert(str_contains($script, 'prefers-color-scheme: dark'));

    foreach (['public/index.php', 'public/career.php', 'public/views/recruiter/layout-start.php', 'public/views/admin/layout-start.php'] as $file) {
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
    $assert(str_contains($index, 'We read your role from your account'), 'the login page explains the unified sign-in');
    $assert(str_contains($index, 'auth-role-note'), 'the login page names the three audiences');
    // The login form must not ask the visitor to pick a role; registration still does.
    $loginForm = substr($index, strpos($index, "\$page === 'login' || \$page === 'register'"), 5200);
    $assert(substr_count($loginForm, 'name="role"') === 1, 'only the registration form exposes a role field');
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
    foreach (['sv-dock-liquid', 'sv-dock-rim', 'sv-dock-caustic'] as $layer) {
        $assert(str_contains($styles, $layer), "missing dock layer {$layer}");
    }
    $assert(str_contains($styles, 'var(--sv-drop)'), 'the droplet uses the theme colour');
    $assert(str_contains($styles, '--sv-slosh'));
    $assert(str_contains($styles, 'mask-composite: exclude'));
    $assert(str_contains($styles, 'prefers-reduced-motion'));
    $assert(str_contains($styles, 'body:has(.sv-dock)'));

    $assert(str_contains($script, 'MOVE_SPRING'));
    $assert(str_contains($script, 'DRAG_SPRING'));
    $assert(str_contains($script, 'setPointerCapture'));
    $assert(str_contains($script, 'scrollLeft'), 'dragging pans the rail');
    $assert(str_contains($script, 'sessionStorage'), 'the capsule flows in from the previous workspace');
    $assert(!str_contains($script, 'setInterval'));
    $assert(str_contains($script, 'prefers-reduced-motion'));
});

$test('Recruiters can record a CTC when a candidate is selected', static function () use ($assert, $root): void {
    $recruiter = file_get_contents($root . '/public/recruiter.php');
    $view = file_get_contents($root . '/public/views/recruiter/applicants.php');
    $assert(str_contains($view, 'name="ctc_lpa"'), 'the applicant form collects a CTC');
    $assert(str_contains($view, 'CTC in LPA (on selection)'));
    $assert(str_contains($recruiter, "\$ctcRaw = recruiter_post('ctc_lpa')"));
    $assert(str_contains($recruiter, "'Enter the offered CTC in LPA, between 0 and 500.'"));
    $assert(str_contains($recruiter, "$nextStatus === 'selected'"));
    $assert(str_contains($recruiter, 'INSERT INTO offers (application_id, ctc_lpa, offer_date, status, created_by)'));
    $assert(str_contains($recruiter, 'recruiter_table_exists($db, \'offers\')'), 'a missing offers table must not break the pipeline');
    $assert(str_contains($recruiter, 'offer.created'));
});

$test('All three docks use the larger droplet geometry', static function () use ($assert, $root): void {
    $admin = file_get_contents($root . '/public/assets/js/admin-nav.js');
    $recruiter = file_get_contents($root . '/public/assets/js/recruiter-nav.js');
    $student = file_get_contents($root . '/public/assets/js/student-nav.js');
    foreach (['admin' => $admin, 'recruiter' => $recruiter, 'student' => $student] as $name => $script) {
        $assert(str_contains($script, 'narrowest * 0.045'), "{$name} dock still uses the small inset");
        $assert(str_contains($script, 'dropHeight'), "{$name} droplet has no protrusion geometry");
    }
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

$test('Docks bulge above the bar with water, on every portal', static function () use ($assert, $root): void {
    $theme = file_get_contents($root . '/public/assets/css/theme.css');
    $assert(str_contains($theme, 'border-radius: 48% 48% 44% 44%'), 'the drop has its own geometry');
    $assert(str_contains($theme, '48% 48% 44% 44% / 34% 34% 66% 66%'), 'droplet is teardrop shaped');
    $assert(str_contains($theme, '.sv-dock-indicator'), 'student droplet styles');
    $assert(str_contains($theme, '.rec-bottom-nav .rec-nav-indicator'), 'recruiter droplet styles');
    $assert(str_contains($theme, 'overflow: visible'), 'dock shells must not clip the drop');

    $student = file_get_contents($root . '/public/assets/js/student-nav.js');
    $admin = file_get_contents($root . '/public/assets/js/admin-nav.js');
    $recruiter = file_get_contents($root . '/public/assets/js/recruiter-nav.js');
    foreach (['student' => $student, 'admin' => $admin, 'recruiter' => $recruiter] as $name => $script) {
        $assert(str_contains($script, 'dropHeight'), "{$name} dock drop is not larger than the bar");
    }
    $assert(str_contains($student, 'track.scrollLeft'), 'student drop follows the rail scroll');
    $assert(str_contains($admin, 'track.scrollLeft'), 'admin drop follows the rail scroll');
    $assert(str_contains($admin, "dock.querySelector('.admin-dock-indicator')"), 'admin indicator lives outside the rail');
    $assert(str_contains($student, "dock.querySelector('.sv-dock-indicator')"), 'student indicator lives outside the rail');
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

$test('No merge-conflict markers remain in key project files', static function () use ($assert, $root): void {
    foreach (['README.md', 'scripts/create-admin.php', 'tests/run.php'] as $file) {
        $contents = file_get_contents($root . '/' . $file);
        $assert(!preg_match('/^(<<<<<<<|=======|>>>>>>>)/m', $contents), "conflict marker in {$file}");
    }
});

echo "\nResults: {$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
