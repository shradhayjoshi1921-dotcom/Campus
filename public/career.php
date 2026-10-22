<?php
declare(strict_types=1);

/**
 * Career studio — the student-facing surface of the intelligence layer.
 *
 * Everything on this page is computed from the student's own saved profile,
 * skills, projects, certifications, assessments and the published roles of
 * approved companies. Nothing is seeded, simulated silently or invented:
 *   • hard eligibility is evaluated first and never overridden by career fit;
 *   • fit is a rule-based estimate with its weights printed next to it;
 *   • "claimed" skills are separated from "demonstrated" ones;
 *   • the what-if panel writes nothing and says so;
 *   • the copilot answers from the same facts (and falls back to rules when no
 *     AI provider is configured).
 */

use App\Core\Database;
use App\Core\Env;
use App\Core\Security;
use App\Data\CareerStore;
use App\Data\StudentData;
use App\Services\CareerFitService;
use App\Services\CareerGraphService;
use App\Services\CopilotService;
use App\Services\EligibilityService;
use App\Services\EvidenceService;
use App\Services\ReadinessService;
use App\Services\RecommendationService;
use App\Services\RoadmapService;
use App\Services\ScoringModel;
use App\Services\SkillGapService;
use App\Services\WhatIfService;
use App\Ai\AiService;

require dirname(__DIR__) . '/bootstrap/app.php';
Security::requireRole(['student']);

$user = Security::currentUser();
$studentId = (int) $user['id'];
$csrf = Security::csrfToken();
$escape = static fn (?string $value): string => Security::escape($value);
// A repeated query parameter arrives as an array; only strings are accepted.
$query = static fn (string $key): string => isset($_GET[$key]) && is_string($_GET[$key]) ? trim($_GET[$key]) : '';
$flash = [];
$error = '';

if (!empty($_SESSION['career_flash']) && is_array($_SESSION['career_flash'])) {
    $flash[] = $_SESSION['career_flash'];
    unset($_SESSION['career_flash']);
}

try {
    $db = Database::connection();
} catch (Throwable $exception) {
    error_log('Career studio database failure: ' . $exception->getMessage());
    $db = null;
}

$model = new ScoringModel();
$evidenceService = new EvidenceService($model);
$data = $db ? new StudentData($db, $model, $evidenceService) : null;
$store = $db ? new CareerStore($db) : null;
$settings = $data ? $data->settings() : [];
if ($settings !== []) {
    $model = new ScoringModel([], $settings);
    $evidenceService = new EvidenceService($model);
}

$fit = new CareerFitService($model);
$gapsService = new SkillGapService();
$recommendations = new RecommendationService();
$roadmaps = new RoadmapService();
$readiness = new ReadinessService($model);
$whatIf = new WhatIfService($fit, new EligibilityService(), $model);
$graph = new CareerGraphService($roadmaps);
// AI is optional: the environment file is read through Env, and with no
// provider configured every answer still comes from the rule engine.
$aiEnv = [];
foreach (['AI_ENABLED', 'AI_API_KEY', 'AI_BASE_URL', 'AI_MODEL', 'AI_TIMEOUT', 'AI_PROVIDER'] as $aiKey) {
    $aiValue = Env::get($aiKey);
    if ($aiValue !== null) {
        $aiEnv[$aiKey] = $aiValue;
    }
}
$copilot = new CopilotService(AiService::fromEnvironment($db, $aiEnv, $settings));

/* ------------------------------------------------------------ POST actions */

if ($db && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
        http_response_code(419);
        $flash[] = ['kind' => 'error', 'text' => 'Your session token expired. Refresh and try again.'];
    } elseif ($action === 'save_roadmap') {
        $jobId = max(0, (int) ($_POST['job_id'] ?? 0));
        $target = $jobId > 0 ? $data->job($jobId) : null;
        $required = $target['required_skills'] ?? [];
        $gapList = $gapsService->prioritise($required, $target['preferred_skills'] ?? [], $data->evidence($studentId), $evidenceService, $model);
        $missing = array_map(static fn (array $row): string => (string) $row['skill'], $gapList);
        if ($missing === []) {
            $missing = ['Interview preparation'];
        }
        $plan = $roadmaps->plan($missing, ['weeks' => 6, 'hours_per_week' => 8, 'target' => (string) ($target['title'] ?? '')]);
        try {
            $store->saveRoadmap($studentId, $jobId ?: null, $target ? 'Plan for ' . $target['title'] : 'My preparation plan', $plan);
            $_SESSION['career_flash'] = ['kind' => 'ok', 'text' => 'Your six-week plan is saved. Nothing was changed on your profile.'];
        } catch (Throwable $exception) {
            error_log('Roadmap save failure: ' . $exception->getMessage());
            $_SESSION['career_flash'] = ['kind' => 'error', 'text' => 'The plan could not be saved. Apply the 2026-10-22 migrations (php scripts/migrate.php) and try again.'];
        }
        header('Location: career.php' . ($jobId > 0 ? '?job=' . $jobId : ''), true, 303);
        exit();
    } elseif ($action === 'task_status') {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $status = (string) ($_POST['status'] ?? '');
        $changed = $store->setTaskStatus($studentId, $itemId, $status);
        $_SESSION['career_flash'] = $changed
            ? ['kind' => 'ok', 'text' => 'Task marked ' . str_replace('_', ' ', $status) . '.']
            : ['kind' => 'warn', 'text' => 'That task could not be updated — it may belong to another student or a different plan.'];
        header('Location: career.php?job=' . max(0, (int) ($_POST['job_id'] ?? 0)) . '#roadmap', true, 303);
        exit();
    } elseif ($action === 'toggle_target') {
        $jobId = (int) ($_POST['job_id'] ?? 0);
        if ($jobId > 0 && $data->job($jobId)) {
            $added = $store->toggleTarget($studentId, $jobId);
            $_SESSION['career_flash'] = ['kind' => 'ok', 'text' => $added ? 'Added to your target roles.' : 'Removed from your target roles.'];
        }
        header('Location: career.php?job=' . $jobId, true, 303);
        exit();
    } elseif ($action === 'ask_copilot') {
        $question = mb_substr(trim((string) ($_POST['question'] ?? '')), 0, 400);
        if ($question === '') {
            $flash[] = ['kind' => 'warn', 'text' => 'Type a question first.'];
        } else {
            $context = [
                'user_id' => $studentId,
                'name' => (string) $user['name'],
                'applications' => array_map(static fn (array $row): array => [
                    'title' => (string) $row['title'],
                    'company' => (string) $row['company_name'],
                    'status' => (string) $row['status'],
                ], $data->applications($studentId)),
                'interviews' => array_map(static fn (array $row): array => [
                    'title' => (string) $row['title'],
                    'company' => (string) $row['company_name'],
                    'when' => (string) $row['scheduled_at'],
                    'mode' => (string) ($row['mode'] ?? ''),
                ], $data->interviews($studentId)),
                'offers' => array_map(static fn (array $row): array => [
                    'title' => (string) $row['title'],
                    'company' => (string) $row['company_name'],
                    'status' => (string) $row['status'],
                    'ctc' => $row['ctc'],
                ], $data->offers($studentId)),
                'skills' => array_column($data->skills($studentId), 'name'),
                'evidence' => array_map(static fn (array $row): string => (string) $row['level'], $data->evidence($studentId)),
            ];
            $answer = $copilot->ask($question, $context);
            $thread = is_array($_SESSION['copilot_thread'] ?? null) ? $_SESSION['copilot_thread'] : [];
            $thread[] = ['question' => $question, 'answer' => $answer['answer'], 'source' => $answer['source'], 'at' => date('H:i')];
            $_SESSION['copilot_thread'] = array_slice($thread, -6);
        }
    }
}

/* --------------------------------------------------------------- page data */

$profile = $data ? $data->profile($studentId) : [];
$skillRows = $data ? $data->skills($studentId) : [];
$skillNames = array_column($skillRows, 'name');
$evidence = $data ? $data->evidence($studentId) : [];
$projects = $data ? $data->projects($studentId) : [];
$certifications = $data ? $data->certifications($studentId) : [];
$resume = $data ? $data->resume($studentId) : null;
$resumeAnalysis = $data ? $data->resumeAnalysis($studentId) : null;
$assessmentHistory = $data ? $data->assessmentHistory($studentId) : [];
$practiceHistory = $store ? $store->practiceHistory($studentId) : [];
$applications = $data ? $data->applications($studentId) : [];
$interviews = $data ? $data->interviews($studentId) : [];
$targets = $store ? $store->targets($studentId) : [];
$savedRoadmap = $store ? $store->activeRoadmap($studentId) : null;
$jobs = $data ? $data->openJobs() : [];
if ($db === null) {
    $error = 'The career studio could not reach the database. Check .env and that the schema is imported.';
}

// Each job needs the academic component the fit formula expects, plus the
// student's evidence so "listed" and "demonstrated" stay different things.
foreach ($jobs as &$job) {
    $job['academic_score'] = $data ? $data->academicScore($profile, $job) : 0.0;
    $job['role_score'] = 100.0;
    $job['evidence'] = $evidence;
}
unset($job);

$ranked = $jobs !== [] ? $recommendations->rank(
    $jobs,
    $skillNames,
    $profile + ['id' => $studentId],
    new EligibilityService(),
    $fit,
) : [];
$grouped = $recommendations->group($ranked);

$selectedJobId = max(0, (int) $query('job'));
if ($selectedJobId === 0 && $targets !== []) {
    $selectedJobId = (int) $targets[0];
}
$selected = null;
foreach ($jobs as $job) {
    if ((int) $job['id'] === $selectedJobId) {
        $selected = $job;
        break;
    }
}
if ($selected === null) {
    $selectedJobId = 0;
}

$readinessResult = $readiness->score([
    'profile' => $profile,
    'resume' => $resume,
    'resume_analysis' => $resumeAnalysis,
    'skills' => $skillRows,
    'evidence' => $evidence,
    'projects' => $projects,
    'certifications' => $certifications,
    'assessments' => $assessmentHistory,
    'practice' => array_map(static fn (array $row): array => [
        'score' => $row['average_score'] !== null ? (float) $row['average_score'] : null,
        'role' => (string) $row['role_title'],
    ], $practiceHistory),
]);

$simulation = null;
$simActive = $query('sim') === '1';
if ($simActive && $jobs !== []) {
    $simSkills = $query('sim_skill');
    $simEvidence = $query('sim_evidence');
    $changes = [];
    if ($query('sim_cgpa') !== '' && is_numeric($query('sim_cgpa'))) {
        $changes['cgpa'] = (float) $query('sim_cgpa');
    }
    if ($simSkills !== '') {
        $changes['add_skills'] = array_slice(array_filter(array_map('trim', preg_split('/[,;]+/', $simSkills) ?: [])), 0, 8);
    }
    if ($simEvidence !== '') {
        $changes['evidence_for'] = array_slice(array_filter(array_map('trim', preg_split('/[,;]+/', $simEvidence) ?: [])), 0, 8);
    }
    if ($changes !== []) {
        $simulation = $whatIf->simulate([
            'profile' => $profile,
            'skills' => $skillNames,
            'evidence' => $evidence,
            'jobs' => $jobs,
        ], $changes);
    } else {
        $flash[] = ['kind' => 'warn', 'text' => 'Add a CGPA, a skill or a skill to demonstrate before running the simulation.'];
    }
}

$selectedGaps = [];
$selectedGraph = null;
if ($selected !== null) {
    $selectedGaps = $data ? $gapsService->prioritise(
        $selected['required_skills'],
        $selected['preferred_skills'],
        $evidence,
        $evidenceService,
        $model,
    ) : [];
    $selectedGraph = $graph->build($selected, $evidence, $projects, $certifications);
}

// Opening a target role records one snapshot (at most every six hours) so the
// student can see whether their preparation moved the estimate.
$fitHistory = [];
if ($selected !== null && $store !== null) {
    foreach ($ranked as $row) {
        if ((int) $row['job']['id'] === $selectedJobId) {
            $store->recordFit($studentId, $selectedJobId, $row['fit'], array_map(
                static fn (array $gap): array => ['skill' => (string) $gap['skill']],
                $selectedGaps,
            ));
            break;
        }
    }
    $fitHistory = $store->fitHistory($studentId, $selectedJobId, 6);
}
$roadmapProgress = $savedRoadmap ? $store->roadmapProgress($savedRoadmap) : null;
$copilotThread = is_array($_SESSION['copilot_thread'] ?? null) ? $_SESSION['copilot_thread'] : [];
$unread = $data ? $data->unreadNotifications($studentId) : 0;
$bandClass = static function (?string $band): string {
    $band = mb_strtolower((string) $band);
    return str_contains($band, 'strong') || str_contains($band, 'ready')
        ? 'intel-band--strong'
        : (str_contains($band, 'low') || str_contains($band, 'early') ? 'intel-band--low' : 'intel-band--warn');
};

$studioTitle = 'Career studio';
$studioEyebrow = 'CAREER INTELLIGENCE';
$studioHeading = 'What your profile can do next.';
$studioLead = 'Explainable estimates from your saved profile, evidence and the roles open right now. Eligibility is always decided by each employer’s published criteria.';
$studioActive = 'career';
$studioUnread = $unread;
$studioFlash = $flash;
require __DIR__ . '/views/partials/studio-start.php';
?>

<?php if ($error !== ''): ?>
    <div class="intel-flash intel-flash--error" role="alert"><?= $escape($error) ?></div>
<?php endif; ?>

<section class="panel" aria-labelledby="readiness-title">
    <div class="intel-section-head">
        <div>
            <p class="eyebrow">PLACEMENT READINESS</p>
            <h2 id="readiness-title"><?= $readinessResult['total'] === null ? 'Not enough information yet' : $escape(number_format((float) $readinessResult['total'], 1)) . '% prepared' ?></h2>
        </div>
        <span class="intel-band <?= $bandClass($readinessResult['band']) ?>"><?= $escape((string) $readinessResult['band']) ?></span>
    </div>
    <?php if ($readinessResult['total'] !== null): ?>
        <div class="intel-bar" role="img" aria-label="Readiness <?= $escape(number_format((float) $readinessResult['total'], 1)) ?> percent">
            <span style="--fill: <?= max(2, min(100, (float) $readinessResult['total'])) ?>%"></span>
        </div>
    <?php endif; ?>
    <div class="intel-grid">
        <?php foreach ($readinessResult['categories'] as $category): ?>
            <article class="intel-row">
                <div>
                    <strong><?= $escape($category['label']) ?></strong>
                    <small><?= $category['score'] === null ? 'Nothing to measure yet' : $escape(number_format((float) $category['score'], 0)) . '%' ?>
                        · weight <?= $escape(number_format((float) $category['weight'] * 100, 0)) ?>%</small>
                    <ul class="intel-list">
                        <?php foreach ($category['evidence'] as $label => $value): ?>
                            <li><?= $escape((string) $label) ?>: <strong><?= $escape(is_bool($value) ? ($value ? 'yes' : 'no') : (is_array($value) ? implode(', ', $value) : (string) $value)) ?></strong></li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="intel-small"><?= $escape($category['next']) ?></p>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
    <p class="intel-note"><?= $escape($readinessResult['notice']) ?></p>
</section>

<section class="panel" aria-labelledby="recommendations-title">
    <div class="intel-section-head">
        <div>
            <p class="eyebrow">RECOMMENDATIONS</p>
            <h2 id="recommendations-title">Open roles, grouped by what they need from you.</h2>
            <p class="intel-muted">Sorted so that roles you are eligible for come first. A high fit score never makes an ineligible role eligible.</p>
        </div>
        <a class="secondary-btn" href="index.php?page=jobs">Browse all roles</a>
    </div>
    <?php if ($ranked === []): ?>
        <div class="intel-empty">
            No published roles from approved companies are open right now. When a recruiter publishes one, your fit, gaps and roadmap appear here — nothing is demoed.
        </div>
    <?php else: ?>
        <?php foreach ($grouped as $key => $group): ?>
            <h3 class="intel-small" style="margin-top:18px"><?= $escape($group['label']) ?> · <?= count($group['rows']) ?></h3>
            <div class="intel-grid">
                <?php foreach ($group['rows'] as $row): $job = $row['job']; $score = $row['fit']; ?>
                    <article class="panel" style="margin:0">
                        <p class="eyebrow"><?= $escape((string) $job['company_name']) ?></p>
                        <h3 style="margin:0 0 6px"><?= $escape((string) $job['title']) ?></h3>
                        <p class="intel-muted"><?= $escape((string) ($job['location'] ?: 'Location not specified')) ?> · min CGPA <?= $escape((string) $job['min_cgpa']) ?></p>
                        <div class="intel-score">
                            <strong><?= $escape(number_format((float) $score['score'], 1)) ?>%</strong>
                            <span>fit estimate</span>
                            <span class="intel-band <?= $bandClass($score['band']) ?>"><?= $escape((string) $score['band']) ?></span>
                        </div>
                        <p class="intel-muted">
                            <?= $row['eligibility']['eligible']
                                ? 'Meets the published eligibility criteria.'
                                : 'Eligibility is not confirmed: ' . $escape(implode('; ', array_merge($row['eligibility']['failed'] ?? [], $row['eligibility']['missing'] ?? []))) ?>
                        </p>
                        <div class="intel-chip-row">
                            <?php foreach (array_slice($score['matched_skills'], 0, 8) as $skill): ?>
                                <span class="intel-chip intel-chip--ok"><?= $escape((string) $skill) ?></span>
                            <?php endforeach; ?>
                            <?php foreach (array_slice($score['missing_skills'], 0, 8) as $skill): ?>
                                <span class="intel-chip intel-chip--missing"><?= $escape((string) $skill) ?></span>
                            <?php endforeach; ?>
                        </div>
                        <details style="margin-top:12px">
                            <summary class="intel-small">Why this score</summary>
                            <ul class="intel-list">
                                <?php foreach ($score['explanation'] as $line): ?>
                                    <li><?= $escape((string) $line) ?></li>
                                <?php endforeach; ?>
                                <?php foreach ($row['reasons'] as $line): ?>
                                    <li><?= $escape((string) $line) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </details>
                        <div class="intel-actions">
                            <a class="secondary-btn" href="career.php?job=<?= (int) $job['id'] ?>#target">Open the plan</a>
                            <form method="post">
                                <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
                                <input type="hidden" name="action" value="toggle_target">
                                <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                                <button class="intel-btn intel-btn--ghost" type="submit"><?= in_array((int) $job['id'], $targets, true) ? 'Remove target' : 'Set as target' ?></button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

<?php if ($selected !== null): ?>
<section class="panel" id="target" aria-labelledby="target-title">
    <div class="intel-section-head">
        <div>
            <p class="eyebrow">TARGET ROLE</p>
            <h2 id="target-title"><?= $escape((string) $selected['title']) ?> · <?= $escape((string) $selected['company_name']) ?></h2>
            <p class="intel-muted"><?= $escape((string) ($selected['employment_type'] ?: 'Employment type not specified')) ?> · <?= $escape((string) ($selected['location'] ?: 'Location not specified')) ?></p>
        </div>
        <a class="secondary-btn" href="career.php">Clear selection</a>
    </div>

    <div class="intel-grid">
        <article class="panel" style="margin:0">
            <h3>Skill evidence</h3>
            <?php if ($selectedGaps === []): ?>
                <p class="intel-muted">Nothing is missing from this role's requirement lists — every listed skill already has enough evidence on your profile.</p>
            <?php else: ?>
                <div class="intel-rows">
                    <?php foreach ($selectedGaps as $gap): ?>
                        <div class="intel-row">
                            <div>
                                <strong><?= $escape((string) $gap['skill']) ?></strong>
                                <span class="intel-chip <?= $gap['status'] === 'claimed' ? 'intel-chip--missing' : 'intel-chip--soft' ?>"><?= $escape((string) $gap['status']) ?></span>
                                <small><?= $escape((string) $gap['why']) ?><br><?= $escape((string) $gap['evidence']) ?></small>
                            </div>
                            <span class="intel-small"><?= $escape((string) $gap['priority']) ?> priority · <?= $escape((string) $gap['type']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="intel-actions">
                <form method="post">
                    <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
                    <input type="hidden" name="action" value="save_roadmap">
                    <input type="hidden" name="job_id" value="<?= (int) $selected['id'] ?>">
                    <button type="submit">Generate and save a six-week plan</button>
                </form>
                <a class="secondary-btn" href="practice.php?job=<?= (int) $selected['id'] ?>">Practise for this role</a>
            </div>
        </article>

        <article class="panel" style="margin:0">
            <h3>Career graph</h3>
            <p class="intel-muted"><?= $escape((string) $selectedGraph['notice']) ?></p>
            <div class="intel-graph">
                <?php foreach ($selectedGraph['nodes'] as $node): ?>
                    <?php if ($node['kind'] !== 'skill') { continue; } ?>
                    <div class="intel-node intel-node--<?= $escape((string) $node['status']) ?>">
                        <div>
                            <strong><?= $escape((string) $node['label']) ?></strong>
                            <small><?= $escape((string) ($node['detail'] ?? '')) ?></small>
                        </div>
                        <span class="intel-chip <?= $node['status'] === 'demonstrated' ? 'intel-chip--ok' : ($node['status'] === 'claimed' ? 'intel-chip--missing' : 'intel-chip--soft') ?>">
                            <?= $escape((string) $node['requirement']) ?> · <?= $escape((string) $node['status']) ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if ($fitHistory !== []): ?>
                <h4 class="intel-small">Fit history for this role</h4>
                <ul class="intel-list">
                    <?php foreach ($fitHistory as $snapshot): ?>
                        <li><?= $escape(date('d M Y, H:i', strtotime((string) $snapshot['computed_at']))) ?> —
                            <strong><?= $escape(number_format((float) $snapshot['fit_score'], 1)) ?>%</strong></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <p class="intel-small">Study resources:
                <?php $resources = array_filter($selectedGraph['nodes'], static fn (array $node): bool => $node['kind'] === 'resource'); ?>
                <?= $resources === [] ? 'none mapped for this role yet.' : $escape(implode(' · ', array_column($resources, 'label'))) ?>
            </p>
        </article>
    </div>
</section>
<?php endif; ?>

<section class="panel" id="roadmap" aria-labelledby="roadmap-title">
    <div class="intel-section-head">
        <div>
            <p class="eyebrow">LEARNING ROADMAP</p>
            <h2 id="roadmap-title"><?= $savedRoadmap ? $escape((string) $savedRoadmap['title']) : 'No saved plan yet' ?></h2>
            <?php if ($roadmapProgress): ?>
                <p class="intel-muted"><?= (int) $roadmapProgress['done'] ?> of <?= (int) $roadmapProgress['total'] ?> tasks complete (<?= $escape(number_format((float) $roadmapProgress['percent'], 0)) ?>%).</p>
            <?php endif; ?>
        </div>
        <?php if ($selected !== null): ?>
            <form method="post">
                <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
                <input type="hidden" name="action" value="save_roadmap">
                <input type="hidden" name="job_id" value="<?= (int) $selected['id'] ?>">
                <button class="intel-btn" type="submit">Rebuild from the current gaps</button>
            </form>
        <?php endif; ?>
    </div>
    <?php if ($savedRoadmap === null): ?>
        <div class="intel-empty">
            Save a plan from a target role and it is stored in <code>learning_roadmaps</code> / <code>roadmap_items</code>, so progress survives a new browser session.
            The plan is generated from the gaps above — no course is sold or sponsored here.
        </div>
    <?php else: ?>
        <?php
        $weeks = [];
        foreach ($savedRoadmap['items'] as $item) {
            $weeks[(int) ($item['week_no'] ?? 1)][] = $item;
        }
        ksort($weeks);
        ?>
        <div class="intel-weeks">
            <?php foreach ($weeks as $week => $items): ?>
                <section class="intel-week">
                    <header>
                        <h3>Week <?= (int) $week ?></h3>
                        <span class="intel-small"><?= array_sum(array_map(static fn (array $item): int => (int) $item['estimated_hours'], $items)) ?> hours planned</span>
                    </header>
                    <?php foreach ($items as $item): ?>
                        <div class="intel-task <?= ($item['status'] ?? '') === 'completed' ? 'intel-task--done' : '' ?>">
                            <div>
                                <strong><?= $escape((string) ($item['title'] ?: $item['skill'])) ?></strong>
                                <span class="intel-small"> · <?= $escape((string) ($item['task_type'] ?? 'task')) ?><?= $item['skill'] !== '' ? ' · ' . $escape((string) $item['skill']) : '' ?></span>
                                <p><?= $escape((string) ($item['practice_task'] ?? '')) ?></p>
                                <?php if (!empty($item['resource'])): ?><p class="intel-small"><?= $escape((string) $item['resource']) ?></p><?php endif; ?>
                            </div>
                            <form method="post">
                                <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
                                <input type="hidden" name="action" value="task_status">
                                <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                                <input type="hidden" name="job_id" value="<?= (int) $selectedJobId ?>">
                                <label class="sr-only" for="status-<?= (int) $item['id'] ?>">Status for <?= $escape((string) $item['title']) ?></label>
                                <select id="status-<?= (int) $item['id'] ?>" name="status">
                                    <?php foreach (['not_started' => 'Not started', 'in_progress' => 'In progress', 'completed' => 'Completed'] as $value => $label): ?>
                                        <option value="<?= $escape($value) ?>" <?= ($item['status'] ?? '') === $value ? 'selected' : '' ?>><?= $escape($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit">Save</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </section>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="panel" aria-labelledby="whatif-title">
    <div class="intel-section-head">
        <div>
            <p class="eyebrow">WHAT-IF SIMULATOR</p>
            <h2 id="whatif-title">What would change if your profile changed?</h2>
            <p class="intel-muted">A simulation, not an edit. Your saved profile, skills and evidence stay exactly as they are.</p>
        </div>
    </div>
    <form class="intel-form intel-form--inline" method="get" action="career.php">
        <input type="hidden" name="sim" value="1">
        <?php if ($selectedJobId > 0): ?><input type="hidden" name="job" value="<?= (int) $selectedJobId ?>"><?php endif; ?>
        <label class="intel-field"><span>CGPA</span>
            <input type="number" step="0.01" min="0" max="10" name="sim_cgpa" value="<?= $escape($query('sim_cgpa')) ?>" placeholder="e.g. 8.2">
        </label>
        <label class="intel-field"><span>Add skills (comma separated)</span>
            <input type="text" name="sim_skill" maxlength="200" value="<?= $escape($query('sim_skill')) ?>" placeholder="e.g. SQL, React">
        </label>
        <label class="intel-field"><span>Demonstrate with a project</span>
            <input type="text" name="sim_evidence" maxlength="200" value="<?= $escape($query('sim_evidence')) ?>" placeholder="e.g. SQL">
        </label>
        <button type="submit">Run simulation</button>
        <?php if ($simActive): ?><a class="intel-btn intel-btn--ghost" href="career.php<?= $selectedJobId > 0 ? '?job=' . (int) $selectedJobId : '' ?>">Reset</a><?php endif; ?>
    </form>
    <?php if ($simulation !== null): ?>
        <p class="intel-note"><?= $escape((string) $simulation['notice']) ?></p>
        <div class="intel-score">
            <strong><?= $escape(number_format((float) $simulation['after_total'], 1)) ?>%</strong>
            <span>average fit across <?= count($jobs) ?> open role(s), from <?= $escape(number_format((float) $simulation['before_total'], 1)) ?>%</span>
            <span class="intel-band <?= $simulation['delta'] > 0 ? 'intel-band--strong' : 'intel-band--warn' ?>"><?= $simulation['delta'] > 0 ? '+' : '' ?><?= $escape(number_format((float) $simulation['delta'], 1)) ?> points</span>
        </div>
        <?php if ($simulation['rows'] === []): ?>
            <div class="intel-empty">That change does not move any open role: the same skills, evidence and academics are already counted.</div>
        <?php else: ?>
            <div class="intel-table-wrap">
                <table class="intel-table">
                    <thead><tr><th scope="col">Role</th><th scope="col">Before</th><th scope="col">After</th><th scope="col">Change</th><th scope="col">Eligibility</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($simulation['rows'], 0, 10) as $row): ?>
                        <tr>
                            <td><?= $escape((string) $row['job']['title']) ?><br><span class="intel-small"><?= $escape((string) $row['job']['company_name']) ?></span></td>
                            <td><?= $escape(number_format((float) $row['before']['fit']['score'], 1)) ?>%</td>
                            <td><?= $escape(number_format((float) $row['after']['fit']['score'], 1)) ?>%</td>
                            <td><?= $row['delta'] > 0 ? '+' : '' ?><?= $escape(number_format((float) $row['delta'], 1)) ?></td>
                            <td><?= $row['eligibility_changed']
                                ? ($row['after']['eligibility']['eligible'] ? 'Would become eligible' : 'Would stop being eligible')
                                : ($row['after']['eligibility']['eligible'] ? 'Eligible' : 'Not eligible') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>

<section class="panel" aria-labelledby="copilot-title">
    <div class="intel-section-head">
        <div>
            <p class="eyebrow">CAREER COPILOT</p>
            <h2 id="copilot-title">Ask about your own record.</h2>
            <p class="intel-muted">
                Answers are built from your applications, interviews, offers, skills and gaps only.
                <?= $copilot->isAiEnabled() ? 'A configured AI provider rewrites the answer from those same facts; it cannot add new ones.' : 'No AI provider is configured, so every answer comes from the deterministic rule engine.' ?>
            </p>
        </div>
    </div>
    <div class="intel-chat">
        <?php foreach ($copilotThread as $exchange): ?>
            <div class="intel-message intel-message--question"><?= $escape((string) $exchange['question']) ?></div>
            <div class="intel-message intel-message--answer">
                <header><b>Copilot</b><span class="intel-small"><?= $escape((string) ($exchange['at'] ?? '')) ?> · <?= $escape((string) ($exchange['source'] ?? 'rules')) ?></span></header>
                <?= $escape((string) $exchange['answer']) ?>
            </div>
        <?php endforeach; ?>
        <?php if ($copilotThread === []): ?>
            <div class="intel-empty">Ask something like “what should I fix before the next interview?”, “where do my applications stand?” or “which skill is blocking me most?”.</div>
        <?php endif; ?>
    </div>
    <form class="intel-form" method="post" action="career.php<?= $selectedJobId > 0 ? '?job=' . (int) $selectedJobId : '' ?>">
        <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
        <input type="hidden" name="action" value="ask_copilot">
        <label class="intel-field"><span>Your question</span>
            <input type="text" name="question" maxlength="400" required placeholder="What is blocking me most right now?">
        </label>
        <button type="submit">Ask the copilot</button>
    </form>
    <p class="intel-note">This answer is not career advice from a person and it cannot promise an interview or an offer. For anything about your eligibility, the employer's published criteria are the only source.</p>
</section>

<?php require __DIR__ . '/views/partials/studio-end.php'; ?>
