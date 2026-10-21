<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap/app.php';
use App\Core\Database;
use App\Core\Security;
use App\Services\EligibilityService;
use App\Services\CareerFitService;
use App\Services\SkillGapService;
use App\Services\RecommendationService;
use App\Services\RoadmapService;

Security::requireRole(['student']);
$user = Security::currentUser();
$csrf = Security::csrfToken();
$e = static fn($v): string => Security::escape((string)($v ?? ''));
$error = '';
$profile = [];
$skills = [];
$ranked = [];
$simulatedSkill = trim((string)($_GET['add_skill'] ?? ''));
if (mb_strlen($simulatedSkill) > 100) $simulatedSkill = '';
try {
    $db = Database::connection();
    $q = $db->prepare('SELECT branch,cgpa,graduation_year FROM student_profiles WHERE user_id=?');
    $q->execute([(int)$user['id']]);
    $profile = $q->fetch() ?: [];
    $q = $db->prepare('SELECT s.name FROM student_skills ss JOIN skills s ON s.id=ss.skill_id WHERE ss.student_id=? ORDER BY s.name');
    $q->execute([(int)$user['id']]);
    $skills = array_column($q->fetchAll(), 'name');
    $q = $db->query("SELECT j.*,c.name company_name FROM jobs j JOIN companies c ON c.id=j.company_id WHERE j.status='published' AND c.approval_status='approved' AND (j.closes_at IS NULL OR j.closes_at>=NOW()) ORDER BY j.created_at DESC");
    $jobs = $q->fetchAll();
    $getJobSkills = $db->prepare('SELECT s.name,js.requirement_type FROM job_skills js JOIN skills s ON s.id=js.skill_id WHERE js.job_id=? ORDER BY js.requirement_type,s.name');
    foreach ($jobs as &$job) {
        $getJobSkills->execute([(int)$job['id']]);
        $job['required_skills'] = [];
        $job['preferred_skills'] = [];
        foreach ($getJobSkills->fetchAll() as $skill) {
            if ($skill['requirement_type'] === 'preferred') $job['preferred_skills'][] = $skill['name'];
            else $job['required_skills'][] = $skill['name'];
        }
        $min = (float)$job['min_cgpa'];
        $cgpa = isset($profile['cgpa']) ? (float)$profile['cgpa'] : null;
        $job['academic_score'] = $cgpa === null ? 0 : ($min <= 0 ? 100 : min(100, round(($cgpa / $min) * 100, 1)));
        $job['role_score'] = 100;
    }
    unset($job);
    $eligibility = new EligibilityService();
    $fit = new CareerFitService();
    $ranked = (new RecommendationService())->rank($jobs, $skills, $profile, $eligibility, $fit);
} catch (Throwable $ex) {
    $error = 'Career insights could not load. Confirm the database schema is imported and .env is configured.';
}
$simulatedSkills = $skills;
if ($simulatedSkill !== '' && !in_array(mb_strtolower($simulatedSkill), array_map('mb_strtolower', $skills), true)) $simulatedSkills[] = $simulatedSkill;
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Career Intelligence · Campus</title>
<meta name="theme-color" content="#fff7f0">
<script>
(function () {
    try {
        var saved = localStorage.getItem('sviet-theme');
        var theme = saved === 'dark' || saved === 'light' ? saved : 'light';
        document.documentElement.dataset.theme = theme;
        document.documentElement.style.colorScheme = theme;
    } catch (error) { document.documentElement.dataset.theme = 'light'; }
})();
</script>
<link rel="stylesheet" href="assets/css/theme.css?v=<?= $e(asset_version()) ?>"><link rel="stylesheet" href="assets/css/app.css?v=<?= $e(asset_version()) ?>"></head><body>
<header class="topbar"><a class="brand" href="index.php" aria-label="Campus home"><span class="brand-mark" aria-hidden="true">C</span><span class="brand-copy"><strong>Campus</strong><small>PLACEMENT INTELLIGENCE</small></span></a><nav aria-label="Student navigation"><a href="index.php?page=dashboard">Dashboard</a><a href="index.php?page=profile">My profile</a><a href="index.php?page=jobs">Jobs</a><a href="index.php?page=applications">Applications</a><form method="post" action="index.php" class="inline-form"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="logout"><button class="nav-cta" type="submit">Sign out</button></form></nav></header>
<main class="career-wrap"><div class="career-head"><div><p class="eyebrow">PHASE 5 · CAREER INTELLIGENCE</p><h1>Your career-fit insights.</h1><p class="career-muted">Transparent rule-based estimates from your saved profile, skills and currently open jobs.</p></div><a class="secondary-btn" href="index.php?page=profile">Update profile ↗</a></div>
<?php if ($error !== ''): ?><div class="career-alert"><?= $e($error) ?></div><?php elseif (!$ranked): ?><section class="career-panel"><h2>No open opportunities to analyze yet</h2><p class="career-muted">When approved companies publish jobs, your fit scores and skill gaps will appear here. No demo jobs are invented.</p></section><?php else: ?>
<section class="career-panel"><h2>What-if analysis</h2><p class="career-muted">Try adding a skill below to estimate how it could change skill overlap. This simulation does not change your saved profile.</p><form class="career-form" method="get"><input name="add_skill" maxlength="100" value="<?= $e($simulatedSkill) ?>" placeholder="e.g. SQL"><button type="submit">Simulate skill ↗</button><?php if ($simulatedSkill !== ''): ?><a class="secondary-btn" href="career.php">Clear</a><?php endif; ?></form><?php if ($simulatedSkill !== ''): ?><p class="career-muted">Simulation active for <strong><?= $e($simulatedSkill) ?></strong>. Scores below show the simulated skill list.</p><?php endif; ?></section>
<div class="career-grid"><?php foreach ($ranked as $item): $job=$item['job']; $studentSkillsForScore=$simulatedSkills; $score=(new CareerFitService())->score($studentSkillsForScore,$job['required_skills'],(float)$job['academic_score'],(float)$job['role_score']); $gaps=(new SkillGapService())->analyze($studentSkillsForScore,$job['required_skills'],$job['preferred_skills']); $roadmap=(new RoadmapService())->build(array_merge($gaps['mandatory_missing'],$gaps['preferred_missing'])); $eligible=$item['eligibility']['eligible']; ?>
<article class="career-panel"><p class="eyebrow"><?= $e($job['company_name']) ?></p><h2><?= $e($job['title']) ?></h2><p class="career-muted"><?= $e($job['location'] ?: 'Location not specified') ?> · Minimum CGPA <?= $e($job['min_cgpa']) ?></p><div class="career-score"><?= $e(number_format((float)$score['score'],1)) ?>% fit</div><p class="career-muted">Rule-based estimate, not a probability of hiring.</p><p><strong>Eligibility:</strong> <?= $eligible ? 'Meets known mandatory criteria' : 'Not yet confirmed' ?></p><?php if ($item['eligibility']['failed']): ?><p><strong>Failed:</strong> <?= $e(implode('; ',$item['eligibility']['failed'])) ?></p><?php endif; ?><?php if ($item['eligibility']['missing']): ?><p><strong>Profile details needed:</strong> <?= $e(implode('; ',$item['eligibility']['missing'])) ?></p><?php endif; ?>
<p><strong>Matched skills</strong><br><?php if ($score['matched_skills']): foreach ($score['matched_skills'] as $s): ?><span class="career-pill"><?= $e($s) ?></span><?php endforeach; else: ?><span class="career-muted">No required skills matched yet.</span><?php endif; ?></p>
<p><strong>Missing required skills</strong><br><?php if ($gaps['mandatory_missing']): foreach ($gaps['mandatory_missing'] as $s): ?><span class="career-pill"><?= $e($s) ?></span><?php endforeach; else: ?><span class="career-muted">No required skill gaps identified.</span><?php endif; ?></p>
<?php if ($gaps['preferred_missing']): ?><p><strong>Preferred skills to build</strong><br><?php foreach ($gaps['preferred_missing'] as $s): ?><span class="career-pill"><?= $e($s) ?></span><?php endforeach; ?></p><?php endif; ?>
<?php if ($roadmap): ?><details><summary>Suggested learning roadmap (<?= count($roadmap) ?> skills)</summary><ol class="career-list"><?php foreach ($roadmap as $step): ?><li><strong><?= $e($step['skill']) ?></strong> — <?= $e($step['priority']) ?> priority<br><span class="career-muted"><?= $e($step['practice']) ?> Project: <?= $e($step['project']) ?></span></li><?php endforeach; ?></ol></details><?php endif; ?><p style="margin-top:16px"><a class="primary-btn" href="index.php?page=jobs">Review and apply ↗</a></p></article>
<?php endforeach; ?></div><?php endif; ?>
<section class="career-panel"><h2>How the score works</h2><p class="career-muted">The current service weights required-skill overlap at 60%, academic score at 25%, and a default role-alignment score at 15%. Missing profile information can reduce or prevent eligibility. These are transparent heuristics, not machine-learning predictions or guarantees.</p></section>
</main><script src="assets/js/theme.js?v=<?= $e(asset_version()) ?>" defer></script></body></html>
