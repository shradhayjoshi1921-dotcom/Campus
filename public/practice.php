<?php
declare(strict_types=1);

/**
 * Practice: text mock interviews and the published assessments.
 *
 * The mock interview is a rubric, not an audition: five named criteria, each
 * met or not met with a note, and an improved skeleton built from the student's
 * own words. There is no voice, face or emotion analysis anywhere in this file
 * and the page says so.
 *
 * Assessments are scored against the stored `correct_answer` only after
 * submission; questions without a usable answer are shown but excluded from
 * scoring rather than treated as wrong.
 */

use App\Core\Database;
use App\Core\Security;
use App\Data\CareerStore;
use App\Data\StudentData;
use App\Services\AssessmentService;
use App\Services\EvidenceService;
use App\Services\InterviewKitService;
use App\Services\MockInterviewService;
use App\Services\ScoringModel;

require dirname(__DIR__) . '/bootstrap/app.php';
Security::requireRole(['student']);

$user = Security::currentUser();
$studentId = (int) $user['id'];
$csrf = Security::csrfToken();
$escape = static fn (?string $value): string => Security::escape($value);
$flash = [];
$error = '';
$result = null;
$feedback = null;

$query = static fn (string $key): string => isset($_GET[$key]) && is_string($_GET[$key]) ? trim($_GET[$key]) : '';
$tab = $query('tab') === 'assessments' ? 'assessments' : 'interview';

if (!empty($_SESSION['practice_flash']) && is_array($_SESSION['practice_flash'])) {
    $flash[] = $_SESSION['practice_flash'];
    unset($_SESSION['practice_flash']);
}

$model = new ScoringModel();
$evidenceService = new EvidenceService($model);
$kit = new InterviewKitService();
$mock = new MockInterviewService($kit);
$assessments = new AssessmentService($model);

try {
    $db = Database::connection();
} catch (Throwable $exception) {
    error_log('Practice database failure: ' . $exception->getMessage());
    $db = null;
}
$data = $db ? new StudentData($db, $model, $evidenceService) : null;
$store = $db ? new CareerStore($db) : null;
if ($data && ($settings = $data->settings()) !== []) {
    $model = new ScoringModel([], $settings);
    $evidenceService = new EvidenceService($model);
    $assessments = new AssessmentService($model);
}

$jobs = $data ? $data->openJobs() : [];
$jobId = max(0, (int) $query('job'));
$selectedJob = null;
foreach ($jobs as $job) {
    if ((int) $job['id'] === $jobId) {
        $selectedJob = $job;
        break;
    }
}

/* ------------------------------------------------------------ POST actions */

if ($db && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
        http_response_code(419);
        $flash[] = ['kind' => 'error', 'text' => 'Your session token expired. Refresh and try again.'];
    } elseif ($action === 'start_practice') {
        $startJobId = max(0, (int) ($_POST['job_id'] ?? 0));
        $role = null;
        foreach ($jobs as $job) {
            if ((int) $job['id'] === $startJobId) {
                $role = $job;
                break;
            }
        }
        $questions = $mock->questions(
            $role['required_skills'] ?? [],
            $role['preferred_skills'] ?? [],
            (string) ($role['title'] ?? ''),
        );
        if ($questions === []) {
            $questions = $mock->questions([], [], '');
        }
        $stored = 0;
        try {
            $stored = $store->createPracticeSession(
                $studentId,
                $startJobId ?: null,
                (string) ($role['title'] ?? 'General practice'),
                $questions,
            );
        } catch (Throwable $exception) {
            error_log('Practice session save failure: ' . $exception->getMessage());
        }
        if ($stored > 0) {
            $_SESSION['practice_flash'] = ['kind' => 'ok', 'text' => 'Session started. Answer one question at a time; nothing is timed.'];
            header('Location: practice.php?session=' . $stored, true, 303);
            exit();
        }
        // No table yet: keep the session in this browser session so the
        // exercise still works, and say that it is not being stored.
        $tempId = 'temp-' . bin2hex(random_bytes(4));
        $_SESSION['practice_temp'][$tempId] = [
            'job_id' => $startJobId,
            'role_title' => (string) ($role['title'] ?? 'General practice'),
            'questions' => $questions,
            'answers' => [],
        ];
        $_SESSION['practice_flash'] = ['kind' => 'warn', 'text' => 'Saved in this browser session only. Apply the 2026-10-22 migrations to keep practice history.'];
        header('Location: practice.php?session=' . rawurlencode($tempId), true, 303);
        exit();
    } elseif ($action === 'answer') {
        $sessionId = (string) ($_POST['session'] ?? '');
        $index = max(0, (int) ($_POST['index'] ?? 0));
        $answer = mb_substr(trim((string) ($_POST['answer'] ?? '')), 0, 4000);
        if ($answer === '') {
            $flash[] = ['kind' => 'warn', 'text' => 'Write an answer before asking for feedback.'];
        } else {
            $question = null;
            if (str_starts_with($sessionId, 'temp-')) {
                $question = $_SESSION['practice_temp'][$sessionId]['questions'][$index] ?? null;
            } else {
                $session = $store->practiceSession($studentId, (int) $sessionId);
                $question = $session['questions_json'][$index] ?? null;
            }
            if ($question === null) {
                $flash[] = ['kind' => 'error', 'text' => 'That question is not part of your session.'];
            } else {
                $feedback = $mock->evaluate($answer, $question) + ['answer' => $answer, 'index' => $index];
                if (str_starts_with($sessionId, 'temp-')) {
                    $_SESSION['practice_temp'][$sessionId]['answers'][$index] = $feedback;
                } else {
                    try {
                        $store->savePracticeAnswer((int) $sessionId, $index, $question, $answer, $feedback);
                    } catch (Throwable $exception) {
                        error_log('Practice answer save failure: ' . $exception->getMessage());
                        $flash[] = ['kind' => 'warn', 'text' => 'Feedback is shown, but this answer could not be stored.'];
                    }
                }
            }
        }
    } elseif ($action === 'finish_practice') {
        $sessionId = (string) ($_POST['session'] ?? '');
        if (!str_starts_with($sessionId, 'temp-')) {
            try {
                $store->completePracticeSession($studentId, (int) $sessionId);
                $_SESSION['practice_flash'] = ['kind' => 'ok', 'text' => 'Session completed and added to your history.'];
            } catch (Throwable $exception) {
                error_log('Practice completion failure: ' . $exception->getMessage());
            }
        }
        header('Location: practice.php', true, 303);
        exit();
    } elseif ($action === 'start_assessment') {
        $assessmentId = (int) ($_POST['assessment_id'] ?? 0);
        if ($store->assessment($assessmentId) === null) {
            $flash[] = ['kind' => 'warn', 'text' => 'That assessment is not open.'];
        } else {
            try {
                $attemptId = $store->startAttempt($assessmentId, $studentId);
                header('Location: practice.php?tab=assessments&attempt=' . $attemptId, true, 303);
                exit();
            } catch (Throwable $exception) {
                error_log('Assessment start failure: ' . $exception->getMessage());
                $flash[] = ['kind' => 'error', 'text' => 'The attempt could not be started. Check the database connection.'];
            }
        }
    } elseif ($action === 'submit_assessment') {
        $attemptId = (int) ($_POST['attempt_id'] ?? 0);
        $assessment = $store->assessment((int) ($_POST['assessment_id'] ?? 0));
        if ($assessment === null) {
            $flash[] = ['kind' => 'warn', 'text' => 'That assessment is not open.'];
        } else {
            $answers = (array) ($_POST['answers'] ?? []);
            $scorable = [];
            $points = [];
            foreach ($assessment['questions'] as $question) {
                if ($question['correct_index'] === null) {
                    continue;
                }
                $scorable[] = [
                    'id' => (int) $question['id'],
                    'correct' => (int) $question['correct_index'],
                    'points' => (float) $question['points'],
                    'skill' => (string) ($question['skill'] ?? ''),
                ];
                $points[(int) $question['id']] = (float) $question['points'];
            }
            if ($scorable === []) {
                $flash[] = ['kind' => 'warn', 'text' => 'This assessment has no answer key, so it cannot be scored. Ask the placement cell to publish one.'];
            } else {
                $result = ['assessment' => $assessment] + $assessments->score($scorable, $answers);
                try {
                    $store->submitAttempt($attemptId, $studentId, $result, $points);
                } catch (Throwable $exception) {
                    error_log('Assessment submit failure: ' . $exception->getMessage());
                    $flash[] = ['kind' => 'warn', 'text' => 'Your score is shown below, but the attempt could not be stored.'];
                }
            }
        }
    }
}

/* --------------------------------------------------------------- page data */

$sessionKey = $query('session');
$session = null;
if ($sessionKey !== '') {
    if (str_starts_with($sessionKey, 'temp-')) {
        $temp = $_SESSION['practice_temp'][$sessionKey] ?? null;
        if (is_array($temp)) {
            $session = [
                'id' => $sessionKey,
                'job_id' => $temp['job_id'],
                'role_title' => $temp['role_title'],
                'questions_json' => $temp['questions'],
                'status' => 'active',
                'answers' => $temp['answers'],
            ];
        }
    } elseif ($store) {
        $session = $store->practiceSession($studentId, (int) $sessionKey);
    }
}
$showIndex = $query('show');
if ($session !== null && $feedback === null && $showIndex !== '' && (int) $showIndex >= 0) {
    $feedbackIndex = (int) $showIndex;
    foreach ($session['answers'] as $row) {
        $index = (int) ($row['question_index'] ?? $row['index'] ?? -1);
        if ($index === $feedbackIndex) {
            $feedback = ($row['feedback'] ?? []) + ['answer' => (string) ($row['answer'] ?? ''), 'index' => $index];
            break;
        }
    }
}

$attemptId = max(0, (int) $query('attempt'));
$attemptAssessment = null;
if ($tab === 'assessments' && $attemptId > 0 && $result === null) {
    // A student may only continue an attempt that belongs to them and is open.
    try {
        $statement = $db->prepare('SELECT assessment_id FROM assessment_attempts WHERE id = ? AND student_id = ? AND submitted_at IS NULL LIMIT 1');
        $statement->execute([$attemptId, $studentId]);
        $assessmentId = (int) ($statement->fetchColumn() ?: 0);
        if ($assessmentId > 0) {
            $attemptAssessment = $store->assessment($assessmentId);
        }
    } catch (Throwable $exception) {
        $attemptAssessment = null;
    }
    if ($attemptAssessment === null) {
        $flash[] = ['kind' => 'warn', 'text' => 'That attempt is not open. Start an assessment again.'];
    }
}

$available = $store ? $store->assessments() : [];
$history = $data ? $data->assessmentHistory($studentId) : [];
$practiceHistory = $store ? $store->practiceHistory($studentId) : [];
$unread = $data ? $data->unreadNotifications($studentId) : 0;

$studioTitle = 'Practice';
$studioEyebrow = 'PRACTICE & EVIDENCE';
$studioHeading = 'Rehearse, then check what was missing.';
$studioLead = 'Text answers scored against a published rubric, and the assessments your placement cell has published. No camera, no microphone, no personality scoring.';
$studioActive = 'practice';
$studioUnread = $unread;
$studioFlash = $flash;
require __DIR__ . '/views/partials/studio-start.php';
?>

<nav class="intel-segmented" aria-label="Practice type">
    <a href="practice.php" class="<?= $tab === 'interview' ? 'is-active' : '' ?>" <?= $tab === 'interview' ? 'aria-current="page"' : '' ?>>Mock interview</a>
    <a href="practice.php?tab=assessments" class="<?= $tab === 'assessments' ? 'is-active' : '' ?>" <?= $tab === 'assessments' ? 'aria-current="page"' : '' ?>>Assessments</a>
</nav>

<?php if ($tab === 'interview'): ?>
    <?php if ($feedback !== null): ?>
        <section class="panel" aria-labelledby="feedback-title">
            <div class="intel-section-head">
                <div>
                    <p class="eyebrow">RUBRIC FEEDBACK</p>
                    <h2 id="feedback-title"><?= (int) ($feedback['score'] ?? 0) ?>% of the rubric met</h2>
                </div>
                <span class="intel-band intel-band--warn">Guidance, not a measurement of you</span>
            </div>
            <p class="intel-muted"><?= $escape((string) ($feedback['feedback'] ?? '')) ?></p>
            <ul class="intel-criteria">
                <?php foreach ((array) ($feedback['criteria'] ?? []) as $name => $criterion): ?>
                    <li class="<?= !empty($criterion['met']) ? 'is-met' : '' ?>">
                        <span><b><?= $escape(ucfirst((string) $name)) ?></b> — <?= $escape((string) ($criterion['note'] ?? '')) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div class="intel-message intel-message--answer" style="margin-top:14px">
                <header><b>A stronger shape for the same answer</b></header>
                <?= $escape((string) ($feedback['improved'] ?? '')) ?>
            </div>
            <p class="intel-small" style="margin-top:10px"><?= $escape((string) ($feedback['next'] ?? '')) ?></p>
        </section>
    <?php endif; ?>

    <?php if ($session !== null): ?>
        <section class="panel" aria-labelledby="session-title">
            <div class="intel-section-head">
                <div>
                    <p class="eyebrow">SESSION</p>
                    <h2 id="session-title"><?= $escape((string) $session['role_title']) ?></h2>
                    <p class="intel-muted">
                        <?= count($session['questions_json']) ?> question(s) ·
                        <?= count($session['answers']) ?> answered ·
                        status <?= $escape((string) $session['status']) ?>
                        <?= str_starts_with((string) $session['id'], 'temp-') ? ' · not stored (migration pending)' : '' ?>
                    </p>
                </div>
                <a class="secondary-btn" href="practice.php">Leave session</a>
            </div>
            <?php foreach ($session['questions_json'] as $index => $question): ?>
                <?php $answerRow = null; foreach ($session['answers'] as $row) { if ((int) ($row['question_index'] ?? $row['index'] ?? -1) === (int) $index) { $answerRow = $row; break; } } ?>
                <article class="intel-question">
                    <p class="eyebrow"><?= $escape(ucfirst((string) ($question['type'] ?? 'technical'))) ?><?= !empty($question['skill']) ? ' · ' . $escape((string) $question['skill']) : '' ?></p>
                    <h3><?= $escape((string) ($question['prompt'] ?? '')) ?></h3>
                    <?php if (!empty($question['rubric'])): ?>
                        <details>
                            <summary class="intel-small">What a strong answer covers</summary>
                            <ul class="intel-list">
                                <?php foreach ((array) $question['rubric'] as $line): ?>
                                    <li><?= $escape((string) $line) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </details>
                    <?php endif; ?>
                    <?php if ($answerRow !== null): ?>
                        <p class="intel-small" style="margin-top:10px">
                            Answered · score <?= (int) ($answerRow['score'] ?? $answerRow['feedback']['score'] ?? 0) ?>%
                            · <a href="practice.php?session=<?= $escape(rawurlencode((string) $session['id'])) ?>&amp;show=<?= (int) $index ?>">read the feedback</a>
                        </p>
                    <?php else: ?>
                        <form class="intel-form" method="post">
                            <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
                            <input type="hidden" name="action" value="answer">
                            <input type="hidden" name="session" value="<?= $escape((string) $session['id']) ?>">
                            <input type="hidden" name="index" value="<?= (int) $index ?>">
                            <label class="intel-field"><span>Your answer</span>
                                <textarea name="answer" maxlength="4000" required placeholder="Situation, what you did, and what happened as a result."></textarea>
                            </label>
                            <button type="submit">Get rubric feedback</button>
                        </form>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
            <div class="intel-actions">
                <form method="post">
                    <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
                    <input type="hidden" name="action" value="finish_practice">
                    <input type="hidden" name="session" value="<?= $escape((string) $session['id']) ?>">
                    <button class="intel-btn intel-btn--ghost" type="submit">Finish this session</button>
                </form>
            </div>
            <p class="intel-note">The rubric checks completeness, structure, specificity, relevance and outcome. It cannot judge how you came across, and it does not record audio, video or emotion.</p>
        </section>
    <?php else: ?>
        <section class="panel" aria-labelledby="start-title">
            <div class="intel-section-head">
                <div>
                    <p class="eyebrow">START A SESSION</p>
                    <h2 id="start-title">Pick the role you are preparing for.</h2>
                    <p class="intel-muted">Questions come from that role's stored requirement list, so they match what the employer says it needs.</p>
                </div>
            </div>
            <form class="intel-form intel-form--inline" method="post">
                <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
                <input type="hidden" name="action" value="start_practice">
                <label class="intel-field"><span>Role</span>
                    <select name="job_id">
                        <option value="0">General practice (no specific role)</option>
                        <?php foreach ($jobs as $job): ?>
                            <option value="<?= (int) $job['id'] ?>" <?= $selectedJob !== null && (int) $selectedJob['id'] === (int) $job['id'] ? 'selected' : '' ?>>
                                <?= $escape((string) $job['title']) ?> · <?= $escape((string) $job['company_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit">Start four questions</button>
            </form>
            <?php if ($practiceHistory !== []): ?>
                <h3 style="margin-top:20px">Previous sessions</h3>
                <div class="intel-table-wrap">
                    <table class="intel-table">
                        <thead><tr><th scope="col">Role</th><th scope="col">Started</th><th scope="col">Status</th><th scope="col">Average rubric</th></tr></thead>
                        <tbody>
                        <?php foreach ($practiceHistory as $row): ?>
                            <tr>
                                <td><a href="practice.php?session=<?= (int) $row['id'] ?>"><?= $escape((string) $row['role_title']) ?></a></td>
                                <td><?= $escape(date('d M Y', strtotime((string) $row['created_at']))) ?></td>
                                <td><?= $escape(ucfirst((string) $row['status'])) ?></td>
                                <td><?= $row['average_score'] === null ? '—' : $escape(number_format((float) $row['average_score'], 0)) . '%' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
<?php else: ?>
    <?php if ($result !== null): ?>
        <section class="panel" aria-labelledby="result-title">
            <div class="intel-section-head">
                <div>
                    <p class="eyebrow">RESULT</p>
                    <h2 id="result-title"><?= $escape((string) $result['assessment']['title']) ?> · <?= $escape(number_format((float) $result['percent'], 1)) ?>%</h2>
                    <p class="intel-muted"><?= (int) $result['correct'] ?> of <?= (int) $result['total'] ?> correct · level: <strong><?= $escape((string) $result['level']) ?></strong></p>
                </div>
                <a class="secondary-btn" href="practice.php?tab=assessments">Back to assessments</a>
            </div>
            <?php if ($result['level'] === 'Not enough evidence'): ?>
                <p class="intel-note">Fewer than five questions is not enough evidence for a level, so none is claimed. The percentage above is still the raw score.</p>
            <?php endif; ?>
            <div class="intel-table-wrap">
                <table class="intel-table">
                    <thead><tr><th scope="col">Question</th><th scope="col">Your answer</th><th scope="col">Result</th></tr></thead>
                    <tbody>
                    <?php foreach ($result['assessment']['questions'] as $question): ?>
                        <?php $reviewRow = null; foreach ($result['review'] as $row) { if ((int) $row['question_id'] === (int) $question['id']) { $reviewRow = $row; break; } } ?>
                        <tr>
                            <td><?= $escape((string) $question['question']) ?></td>
                            <td><?= $reviewRow === null || $reviewRow['given'] === null ? 'Not answered' : $escape((string) ($question['options'][$reviewRow['given']] ?? $reviewRow['given'])) ?></td>
                            <td><?= $reviewRow === null ? 'Not scored' : ($reviewRow['is_correct'] ? 'Correct' : 'Incorrect') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="intel-note">A score of 60% or more is recorded as assessment evidence for the question skills, which strengthens your career fit. It is not a certificate.</p>
        </section>
    <?php elseif ($attemptAssessment !== null): ?>
        <section class="panel" aria-labelledby="take-title">
            <div class="intel-section-head">
                <div>
                    <p class="eyebrow">IN PROGRESS</p>
                    <h2 id="take-title"><?= $escape((string) $attemptAssessment['title']) ?></h2>
                    <p class="intel-muted"><?= count($attemptAssessment['questions']) ?> question(s). Answers are scored once you submit.</p>
                </div>
                <a class="secondary-btn" href="practice.php?tab=assessments">Cancel</a>
            </div>
            <form class="intel-form" method="post">
                <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
                <input type="hidden" name="action" value="submit_assessment">
                <input type="hidden" name="attempt_id" value="<?= (int) $attemptId ?>">
                <input type="hidden" name="assessment_id" value="<?= (int) $attemptAssessment['id'] ?>">
                <?php foreach ($attemptAssessment['questions'] as $number => $question): ?>
                    <fieldset class="intel-question" style="border:1px solid var(--sv-line);">
                        <legend class="intel-small">Question <?= (int) $number + 1 ?><?= !empty($question['skill']) ? ' · ' . $escape((string) $question['skill']) : '' ?></legend>
                        <h3><?= $escape((string) $question['question']) ?></h3>
                        <?php if ($question['options'] === []): ?>
                            <p class="intel-small">No options are stored for this question, so it cannot be scored.</p>
                        <?php else: ?>
                            <?php foreach ($question['options'] as $optionIndex => $option): ?>
                                <label class="intel-check">
                                    <input type="radio" name="answers[<?= (int) $question['id'] ?>]" value="<?= (int) $optionIndex ?>" required>
                                    <span><?= $escape((string) $option) ?></span>
                                </label>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </fieldset>
                <?php endforeach; ?>
                <button type="submit">Submit and score</button>
            </form>
        </section>
    <?php else: ?>
        <section class="panel" aria-labelledby="assessments-title">
            <div class="intel-section-head">
                <div>
                    <p class="eyebrow">ASSESSMENTS</p>
                    <h2 id="assessments-title">Published by the placement cell.</h2>
                    <p class="intel-muted">Only published assessments appear here. A draft is invisible to students.</p>
                </div>
            </div>
            <?php if ($available === []): ?>
                <div class="intel-empty">No assessment has been published yet. Your TPO can publish one from the admin workspace; nothing is generated on your behalf.</div>
            <?php else: ?>
                <div class="intel-rows">
                    <?php foreach ($available as $assessment): ?>
                        <div class="intel-row">
                            <div>
                                <strong><?= $escape((string) $assessment['title']) ?></strong>
                                <small><?= (int) $assessment['question_count'] ?> question(s)<?= !empty($assessment['description']) ? ' · ' . $escape((string) $assessment['description']) : '' ?></small>
                            </div>
                            <form method="post">
                                <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
                                <input type="hidden" name="action" value="start_assessment">
                                <input type="hidden" name="assessment_id" value="<?= (int) $assessment['id'] ?>">
                                <button class="intel-btn" type="submit" <?= (int) $assessment['question_count'] === 0 ? 'disabled' : '' ?>>Start</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ($history !== []): ?>
                <h3 style="margin-top:20px">Your attempts</h3>
                <div class="intel-table-wrap">
                    <table class="intel-table">
                        <thead><tr><th scope="col">Assessment</th><th scope="col">Score</th><th scope="col">Submitted</th></tr></thead>
                        <tbody>
                        <?php foreach ($history as $row): ?>
                            <tr>
                                <td><?= $escape((string) $row['title']) ?></td>
                                <td><?= $escape(number_format((float) $row['percent'], 1)) ?>%</td>
                                <td><?= $escape(date('d M Y', strtotime((string) $row['submitted_at']))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <p class="intel-note">A question is scored only when its stored answer key can be resolved. Unscorable questions are shown but never counted as wrong.</p>
        </section>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/views/partials/studio-end.php'; ?>
