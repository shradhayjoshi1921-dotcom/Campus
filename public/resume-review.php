<?php
declare(strict_types=1);

/**
 * Resume intelligence.
 *
 * The student uploads a PDF on the profile page; this page extracts the text
 * locally (no AI provider required), shows what was recognised, checks it
 * against one job's requirements, and applies only the fields the student ticks.
 *
 * Extraction never writes to `student_skills`, `student_projects` or
 * `student_certifications` on its own. Approval is a separate, explicit step,
 * and an approved skill is stored as a *claim* with a resume evidence row — not
 * as a verified skill.
 */

use App\Core\Database;
use App\Core\Security;
use App\Data\CareerStore;
use App\Data\StudentData;
use App\Services\EvidenceService;
use App\Services\ResumeAnalysisService;
use App\Services\ResumeTextService;
use App\Services\ScoringModel;

require dirname(__DIR__) . '/bootstrap/app.php';
Security::requireRole(['student']);

$user = Security::currentUser();
$studentId = (int) $user['id'];
$csrf = Security::csrfToken();
$escape = static fn (?string $value): string => Security::escape($value);
$flash = [];
$error = '';
$comparison = null;
$improved = null;

if (!empty($_SESSION['resume_flash']) && is_array($_SESSION['resume_flash'])) {
    $flash[] = $_SESSION['resume_flash'];
    unset($_SESSION['resume_flash']);
}

$model = new ScoringModel();
$evidenceService = new EvidenceService($model);
$analysis = new ResumeAnalysisService($evidenceService);
$textService = new ResumeTextService();

try {
    $db = Database::connection();
} catch (Throwable $exception) {
    error_log('Resume review database failure: ' . $exception->getMessage());
    $db = null;
}
$data = $db ? new StudentData($db, $model, $evidenceService) : null;
$store = $db ? new CareerStore($db) : null;
if ($data && ($settings = $data->settings()) !== []) {
    $model = new ScoringModel([], $settings);
    $evidenceService = new EvidenceService($model);
    $analysis = new ResumeAnalysisService($evidenceService);
}

$resume = $data ? $data->resume($studentId) : null;
$stored = $data ? $data->resumeAnalysis($studentId) : null;
$text = (string) ($stored['extracted_text'] ?? '');

if ($db && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
        http_response_code(419);
        $flash[] = ['kind' => 'error', 'text' => 'Your session token expired. Refresh and try again.'];
    } elseif ($action === 'analyse') {
        if ($resume === null) {
            $flash[] = ['kind' => 'warn', 'text' => 'Upload a PDF on your profile page first.'];
        } else {
            $path = dirname(__DIR__) . '/storage/uploads/resumes/' . basename((string) $resume['stored_name']);
            $extracted = $textService->extract($path);
            if (!$extracted['ok']) {
                $store->saveResumeAnalysis((int) $resume['id'], $studentId, '', [], [], false, $extracted['reason']);
                $_SESSION['resume_flash'] = ['kind' => 'error', 'text' => $extracted['reason'] . ' Nothing was added to your profile.'];
            } else {
                $fields = $analysis->extract($extracted['text']);
                $review = $analysis->review($extracted['text']);
                $store->saveResumeAnalysis((int) $resume['id'], $studentId, $extracted['text'], $fields, $review, true);
                $_SESSION['resume_flash'] = [
                    'kind' => 'ok',
                    'text' => 'Read ' . $extracted['pages'] . ' page(s). Review the recognised fields below, then tick what should be added to your profile.',
                ];
            }
            header('Location: resume-review.php', true, 303);
            exit();
        }
    } elseif ($action === 'approve') {
        if ($stored === null || $resume === null) {
            $flash[] = ['kind' => 'warn', 'text' => 'Analyse the resume before approving fields.'];
        } else {
            $approved = [
                'skills' => array_slice(array_values(array_filter((array) ($_POST['skills'] ?? []), 'is_string')), 0, 30),
                'projects' => array_slice(array_values(array_filter((array) ($_POST['projects'] ?? []), 'is_string')), 0, 8),
                'certifications' => array_slice(array_values(array_filter((array) ($_POST['certifications'] ?? []), 'is_string')), 0, 8),
            ];
            try {
                $store->approveResumeFields($studentId, (int) $resume['id'], $approved);
                $_SESSION['resume_flash'] = [
                    'kind' => 'ok',
                    'text' => 'Added ' . count($approved['skills']) . ' skill claim(s), ' . count($approved['projects']) . ' project(s) and ' . count($approved['certifications']) . ' certification(s). Skills are recorded as claims until a project, assessment or staff check demonstrates them.',
                ];
            } catch (Throwable $exception) {
                error_log('Resume approval failure: ' . $exception->getMessage());
                $_SESSION['resume_flash'] = ['kind' => 'error', 'text' => 'Those fields could not be saved. Check that the migrations have been applied.'];
            }
            header('Location: resume-review.php', true, 303);
            exit();
        }
    } elseif ($action === 'compare') {
        $jobId = (int) ($_POST['job_id'] ?? 0);
        $job = $data ? $data->job($jobId) : null;
        if ($job === null) {
            $flash[] = ['kind' => 'warn', 'text' => 'Pick one of the open roles first.'];
        } elseif ($text === '') {
            $flash[] = ['kind' => 'warn', 'text' => 'Analyse the resume first, so there is text to compare.'];
        } else {
            $comparison = ['job' => $job] + $analysis->compare($text, $job);
        }
    } elseif ($action === 'improve') {
        $bullet = mb_substr(trim((string) ($_POST['bullet'] ?? '')), 0, 400);
        if ($bullet === '') {
            $flash[] = ['kind' => 'warn', 'text' => 'Paste one bullet point to rewrite.'];
        } else {
            $improved = $analysis->improveBullet($bullet);
        }
    }
}

$jobs = $data ? $data->openJobs() : [];
$fields = is_array($stored['extracted_json'] ?? null) ? $stored['extracted_json'] : [];
$review = is_array($stored['review_json'] ?? null) ? $stored['review_json'] : [];
$unread = $data ? $data->unreadNotifications($studentId) : 0;

$studioTitle = 'Resume review';
$studioEyebrow = 'RESUME INTELLIGENCE';
$studioHeading = 'What your resume actually says.';
$studioLead = 'Text is extracted from your own PDF on this server. Nothing is invented, nothing is written to your profile until you tick it.';
$studioActive = 'resume';
$studioUnread = $unread;
$studioFlash = $flash;
require __DIR__ . '/views/partials/studio-start.php';
?>

<?php if ($db === null): ?>
    <div class="intel-flash intel-flash--error" role="alert">The resume review could not reach the database. Check .env and the schema.</div>
<?php endif; ?>

<section class="panel" aria-labelledby="resume-state-title">
    <div class="intel-section-head">
        <div>
            <p class="eyebrow">YOUR PDF</p>
            <h2 id="resume-state-title"><?= $resume ? $escape((string) $resume['original_name']) : 'No resume uploaded' ?></h2>
            <p class="intel-muted">
                <?php if ($resume): ?>
                    Uploaded <?= $escape(date('d M Y', strtotime((string) $resume['created_at']))) ?> ·
                    <?= $escape(number_format(((int) $resume['file_size']) / 1024, 0)) ?> KB ·
                    analysis status: <strong><?= $escape((string) ($stored['status'] ?? 'not analysed')) ?></strong>
                <?php else: ?>
                    Upload a PDF from your profile page. This page reads text-based PDFs; scanned images cannot be read and it will say so rather than guessing.
                <?php endif; ?>
            </p>
        </div>
        <div class="intel-actions">
            <?php if ($resume): ?>
                <a class="secondary-btn" href="index.php?page=resume&amp;id=<?= (int) $resume['id'] ?>" target="_blank" rel="noopener">View PDF</a>
                <form method="post">
                    <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
                    <input type="hidden" name="action" value="analyse">
                    <button class="intel-btn" type="submit"><?= $stored ? 'Re-read the PDF' : 'Read the PDF' ?></button>
                </form>
            <?php else: ?>
                <a class="primary-btn" href="index.php?page=profile#resume">Upload a PDF ↗</a>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($stored && !empty($stored['failure_reason'])): ?>
        <div class="intel-flash intel-flash--error" role="alert"><?= $escape((string) $stored['failure_reason']) ?></div>
    <?php endif; ?>
    <p class="intel-note">Extraction is local and deterministic: a small PDF text parser, no external service. Encrypted PDFs, scanned images and files over 8 MB are refused with a reason instead of returning an empty “success”.</p>
</section>

<?php if ($fields !== []): ?>
<section class="panel" aria-labelledby="fields-title">
    <div class="intel-section-head">
        <div>
            <p class="eyebrow">RECOGNISED FIELDS</p>
            <h2 id="fields-title">Tick only what is true.</h2>
            <p class="intel-muted">
                Sections detected: <?= $fields['sections_found'] === [] ? 'none' : $escape(implode(', ', array_map(static fn ($s): string => (string) $s, $fields['sections_found']))) ?>.
                <?= !empty($stored['approved_at']) ? 'Approved on ' . $escape(date('d M Y', strtotime((string) $stored['approved_at']))) . '.' : 'Nothing has been added to your profile yet.' ?>
            </p>
        </div>
    </div>
    <form method="post">
        <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
        <input type="hidden" name="action" value="approve">
        <div class="intel-grid">
            <?php foreach (['skills' => 'Skills found in the text', 'projects' => 'Projects', 'certifications' => 'Certifications'] as $key => $label): ?>
                <article class="panel" style="margin:0">
                    <h3><?= $escape($label) ?> · <?= count((array) ($fields[$key] ?? [])) ?></h3>
                    <?php if (($fields[$key] ?? []) === []): ?>
                        <p class="intel-muted">Nothing recognised in this section.</p>
                    <?php else: ?>
                        <div class="intel-rows">
                            <?php foreach ((array) $fields[$key] as $index => $value): ?>
                                <?php $value = is_array($value) ? (string) ($value['title'] ?? '') : (string) $value; ?>
                                <?php if (trim($value) === '') { continue; } ?>
                                <label class="intel-check">
                                    <input type="checkbox" name="<?= $escape($key) ?>[]" value="<?= $escape($value) ?>" id="<?= $escape($key . '-' . $index) ?>">
                                    <span><?= $escape($value) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
        <div class="intel-actions">
            <button class="intel-btn" type="submit">Add the ticked fields to my profile</button>
            <a class="secondary-btn" href="index.php?page=profile">Edit profile instead</a>
        </div>
    </form>
    <p class="intel-note">Skills added this way are stored as claims with a resume evidence row, so the career-fit engine can tell “listed on the CV” from “demonstrated by a project or assessment”.</p>
</section>
<?php endif; ?>

<?php if ($review !== []): ?>
<section class="panel" aria-labelledby="review-title">
    <div class="intel-section-head">
        <div>
            <p class="eyebrow">CHECKS</p>
            <h2 id="review-title">Things to check, not a grade.</h2>
            <p class="intel-muted">Each line is a heuristic on the extracted text. Read it, then decide — none of it changes your profile.</p>
        </div>
    </div>
    <ul class="intel-criteria">
        <?php foreach ($review as $note): ?>
            <li class="<?= ($note['level'] ?? '') === 'info' ? 'is-met' : '' ?>">
                <span><b><?= ($note['level'] ?? '') === 'info' ? 'Check' : 'Fix first' ?></b> — <?= $escape((string) ($note['message'] ?? '')) ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<section class="panel" aria-labelledby="compare-title">
    <div class="intel-section-head">
        <div>
            <p class="eyebrow">MATCH AGAINST A ROLE</p>
            <h2 id="compare-title">Does this resume mention what the role asks for?</h2>
            <p class="intel-muted">A word-boundary search of your own text. It quotes the line where each skill appears so you can check it yourself.</p>
        </div>
    </div>
    <?php if ($jobs === []): ?>
        <div class="intel-empty">No open roles to compare against right now.</div>
    <?php else: ?>
        <form class="intel-form intel-form--inline" method="post">
            <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
            <input type="hidden" name="action" value="compare">
            <label class="intel-field"><span>Role</span>
                <select name="job_id" required>
                    <?php foreach ($jobs as $job): ?>
                        <option value="<?= (int) $job['id'] ?>"><?= $escape((string) $job['title']) ?> · <?= $escape((string) $job['company_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit">Compare</button>
        </form>
    <?php endif; ?>

    <?php if ($comparison !== null): ?>
        <h3 style="margin-top:18px"><?= $escape((string) $comparison['job']['title']) ?></h3>
        <p class="intel-muted"><?= $escape((string) $comparison['summary']) ?></p>
        <div class="intel-grid">
            <article>
                <h4 class="intel-small">Mentioned in the resume</h4>
                <div class="intel-chip-row">
                    <?php foreach ($comparison['matched'] as $skill): ?>
                        <span class="intel-chip intel-chip--ok"><?= $escape((string) $skill) ?></span>
                    <?php endforeach; ?>
                </div>
                <?php if ($comparison['evidence'] !== []): ?>
                    <ul class="intel-list">
                        <?php foreach ($comparison['evidence'] as $skill => $line): ?>
                            <li><strong><?= $escape((string) $skill) ?></strong> — <?= $escape((string) $line) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </article>
            <article>
                <h4 class="intel-small">Not found in the text</h4>
                <div class="intel-chip-row">
                    <?php foreach ($comparison['missing'] as $skill): ?>
                        <span class="intel-chip intel-chip--missing"><?= $escape((string) $skill) ?></span>
                    <?php endforeach; ?>
                </div>
                <?php if ($comparison['preferred_matched'] !== []): ?>
                    <p class="intel-small">Preferred skills mentioned: <?= $escape(implode(', ', $comparison['preferred_matched'])) ?></p>
                <?php endif; ?>
                <p class="intel-note">Only add a missing skill if you can actually use it — interviewers ask follow-up questions, and your profile should survive them.</p>
            </article>
        </div>
    <?php endif; ?>
</section>

<section class="panel" aria-labelledby="bullet-title">
    <div class="intel-section-head">
        <div>
            <p class="eyebrow">BULLET REWRITE</p>
            <h2 id="bullet-title">Make one line say what you did.</h2>
            <p class="intel-muted">Rule-based rewrite using your own words. It never invents a number or a technology.</p>
        </div>
    </div>
    <form class="intel-form" method="post">
        <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
        <input type="hidden" name="action" value="improve">
        <label class="intel-field"><span>One bullet point from your resume</span>
            <textarea name="bullet" maxlength="400" required placeholder="e.g. worked on the college website"></textarea>
        </label>
        <button type="submit">Rewrite it</button>
    </form>
    <?php if ($improved !== null): ?>
        <div class="intel-messages" style="margin-top:14px">
            <div class="intel-message intel-message--question"><?= $escape((string) $improved['original']) ?></div>
            <div class="intel-message intel-message--answer"><?= $escape((string) $improved['suggestion']) ?>
                <p class="intel-small" style="margin:8px 0 0"><?= $escape((string) $improved['reason']) ?></p>
            </div>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/views/partials/studio-end.php'; ?>
