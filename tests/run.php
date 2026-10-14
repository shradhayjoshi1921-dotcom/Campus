<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/Services/EligibilityService.php';
require dirname(__DIR__).'/app/Services/CareerFitService.php';
require dirname(__DIR__).'/app/Services/SkillGapService.php';
require dirname(__DIR__).'/app/Services/RecommendationService.php';
require dirname(__DIR__).'/app/Services/RoadmapService.php';
use App\Services\EligibilityService; use App\Services\CareerFitService; use App\Services\SkillGapService; use App\Services\RecommendationService; use App\Services\RoadmapService;
$passed=0;$failed=0;$test=function(string $name, callable $fn)use(&$passed,&$failed){try{$fn();$passed++;echo "PASS $name\n";}catch(Throwable $e){$failed++;echo "FAIL $name: {$e->getMessage()}\n";}};
$assert=fn($condition,$message='assertion failed')=> $condition ?: throw new RuntimeException($message);
$test('Eligibility passes matching profile',function()use($assert){$r=(new EligibilityService())->evaluate(['cgpa'=>8.1,'branch'=>'CSE','graduation_year'=>2027],['min_cgpa'=>7.0,'eligible_branch'=>'CSE','graduation_year'=>2027]);$assert($r['eligible']);$assert(count($r['failed'])===0);});
$test('Eligibility rejects hard CGPA failure',function()use($assert){$r=(new EligibilityService())->evaluate(['cgpa'=>6.2,'branch'=>'CSE'],['min_cgpa'=>7.0,'eligible_branch'=>'CSE']);$assert(!$r['eligible']);$assert(count($r['failed'])===1);});
$test('Missing qualification is not silently eligible',function()use($assert){$r=(new EligibilityService())->evaluate(['branch'=>'CSE'],['min_cgpa'=>6.0]);$assert(!$r['eligible']);$assert(count($r['missing'])===1);});
$test('Fit score uses documented weights and reports gaps',function()use($assert){$r=(new CareerFitService())->score(['PHP','SQL'],['php','javascript'],80,100);$assert($r['score']===65.0,(string)$r['score']);$assert($r['matched_skills']===['php']);$assert($r['missing_skills']===['javascript']);});
$test('Skill-gap analysis separates mandatory and preferred',function()use($assert){$r=(new SkillGapService())->analyze(['PHP'],['PHP','SQL'],['React']);$assert($r['mandatory_missing']===['sql']);$assert($r['preferred_missing']===['react']);});
<<<<<<< HEAD

$test('Admin portal restricts access to TPO and admin roles',function()use($assert){$s=file_get_contents(dirname(__DIR__).'/public/admin.php');$assert(str_contains($s,"Security::requireRole(['tpo','admin'])"),'admin role gate missing');});
$test('Admin actions verify CSRF before state changes',function()use($assert){$s=file_get_contents(dirname(__DIR__).'/public/admin.php');$assert(strpos($s,"Security::verifyCsrf")!==false,'CSRF check missing');$assert(strpos($s,"user_decision")!==false,'approval workflow missing');});
$test('Admin portal enforces company approval before publishing jobs',function()use($assert){$s=file_get_contents(dirname(__DIR__).'/public/admin.php');$assert(strpos($s,'Approve the company before publishing its job.')!==false,'company approval guard missing');});

=======
>>>>>>> 23cc9fe26f47d37e38e13aab15b19be44757a25b
$test('Roadmap gives actionable fallback for unknown skill',function()use($assert){$r=(new RoadmapService())->build(['Rust']);$assert($r[0]['skill']==='Rust' && str_contains($r[0]['practice'],'fundamentals'));});
$test('Recommendations exclude unpublished jobs',function()use($assert){$r=(new RecommendationService())->rank([['status'=>'draft','required_skills'=>[]],['status'=>'published','required_skills'=>[]]],[],['cgpa'=>8,'branch'=>'CSE'],new EligibilityService(),new CareerFitService());$assert(count($r)===1);$assert($r[0]['job']['status']==='published');});
$schema=file_get_contents(dirname(__DIR__).'/database/schema.sql');$front=file_get_contents(dirname(__DIR__).'/public/index.php');
$test('Schema seeds no fake records',function()use($assert,$schema){$assert(!preg_match('/INSERT\s+INTO\s+(users|companies|jobs|applications)\b/i',$schema));});
$test('Registration and sign-in forms include CSRF',function()use($assert,$front){$assert(substr_count($front,'name="_csrf"')>=2);$assert(str_contains($front,'password_hash('));$assert(str_contains($front,'password_verify('));});
$test('Student profile route includes guarded profile and skill updates',function()use($assert,$front){$assert(str_contains($front,'save_profile'));$assert(str_contains($front,'save_skills'));$assert(str_contains($front,"Security::requireRole(['student'])"));$assert(str_contains($front,'INSERT INTO student_profiles'));});
<<<<<<< HEAD
=======
<<<<<<< HEAD
>>>>>>> 23cc9fe26f47d37e38e13aab15b19be44757a25b
$test('Job listing only exposes published jobs from approved companies',function()use($assert,$front){$assert(str_contains($front,"j.status='published' AND c.approval_status='approved'"));$assert(str_contains($front,"j.closes_at IS NULL OR j.closes_at>=NOW()"));});
$test('Applications require student role and CSRF validation',function()use($assert,$front){$assert(str_contains($front,"Security::requireRole(['student'])"));$assert(str_contains($front,'elseif ($action===\'apply_job\')'));$assert(str_contains($front,'name="_csrf"'));});
$test('Database prevents duplicate applications',function()use($assert,$schema){$assert(str_contains($schema,'UNIQUE KEY uq_job_student(job_id,student_id)'));});
$test('Application submission records status history',function()use($assert,$front){$assert(str_contains($front,'application_status_history'));$assert(str_contains($front,"'submitted','Application submitted by student'"));});
<<<<<<< HEAD
$career=file_get_contents(dirname(__DIR__).'/public/career.php');
$adminSetup=file_get_contents(dirname(__DIR__).'/scripts/create-admin.php');
$test('Career intelligence route is student-only and computes fit, gaps, recommendations and roadmap',function()use($assert,$career){$assert(str_contains($career,"Security::requireRole(['student'])"));$assert(str_contains($career,'RecommendationService'));$assert(str_contains($career,'SkillGapService'));$assert(str_contains($career,'RoadmapService'));$assert(str_contains($career,'What-if analysis'));});
$test('Admin bootstrap is CLI-only, password-hashed, and refuses a second admin',function()use($assert,$adminSetup){$assert(str_contains($adminSetup,"PHP_SAPI !== 'cli'"));$assert(str_contains($adminSetup,'password_hash('));$assert(str_contains($adminSetup,"role='admin'"));$assert(str_contains($adminSetup,'initial bootstrap is disabled'));});
$test('Student navigation links to career insights and role workspaces',function()use($assert,$front){$assert(str_contains($front,'href="career.php"'));$assert(str_contains($front,'href="recruiter.php"'));$assert(str_contains($front,'href="admin.php"'));});
=======
=======
>>>>>>> origin/main
>>>>>>> 23cc9fe26f47d37e38e13aab15b19be44757a25b
echo "\nResults: {$passed} passed, {$failed} failed\n";exit($failed?1:0);
