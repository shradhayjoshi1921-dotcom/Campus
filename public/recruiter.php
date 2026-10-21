<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\Security;

require dirname(__DIR__) . '/bootstrap/app.php';
Security::requireRole(['recruiter']);

$user = Security::currentUser();
if (!$user) {
    header('Location: index.php?page=login');
    exit;
}

function recruiter_h(?string $value): string
{
    return Security::escape($value);
}

function recruiter_post(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

function recruiter_get(string $key): string
{
    $value = $_GET[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

function recruiter_section(string $candidate, string $fallback = 'overview'): string
{
    $sections = ['overview', 'company', 'jobs', 'applicants', 'interviews'];
    return in_array($candidate, $sections, true) ? $candidate : $fallback;
}

function recruiter_positive_id(mixed $value): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT);
    return $id !== false && $id > 0 ? (int) $id : 0;
}

function recruiter_company(PDO $db, int $userId): ?array
{
    $statement = $db->prepare(
        'SELECT rp.company_id, rp.designation, c.name AS company_name, c.website, c.industry, c.description, c.approval_status '
        . 'FROM recruiter_profiles rp LEFT JOIN companies c ON c.id = rp.company_id WHERE rp.user_id = ? LIMIT 1',
    );
    $statement->execute([$userId]);
    $company = $statement->fetch(PDO::FETCH_ASSOC);
    return $company ?: null;
}

function recruiter_audit(PDO $db, int $actorId, string $action, string $entity, ?int $id, array $metadata = []): void
{
    $statement = $db->prepare(
        'INSERT INTO audit_logs (actor_id, action, entity_type, entity_id, ip_hash, metadata_json) VALUES (?, ?, ?, ?, ?, ?)',
    );
    $statement->execute([
        $actorId,
        $action,
        $entity,
        $id,
        isset($_SERVER['REMOTE_ADDR']) ? hash('sha256', (string) $_SERVER['REMOTE_ADDR']) : null,
        $metadata ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null,
    ]);
}

function recruiter_notify(PDO $db, int $userId, string $title, string $body): void
{
    $statement = $db->prepare('INSERT INTO notifications (user_id, title, body) VALUES (?, ?, ?)');
    $statement->execute([$userId, $title, $body]);
}

function recruiter_flash_redirect(string $section, string $kind, string $message, array $query = []): never
{
    $_SESSION['recruiter_flash'] = ['kind' => $kind, 'message' => $message];
    $query['section'] = recruiter_section($section);
    header('Location: recruiter.php?' . http_build_query($query), true, 303);
    exit;
}

function recruiter_table_exists(PDO $db, string $table): bool
{
    try {
        $statement = $db->prepare('SHOW TABLES LIKE ?');
        $statement->execute([$table]);
        return (bool) $statement->fetchColumn();
    } catch (Throwable $exception) {
        return false;
    }
}

function recruiter_parse_datetime(string $value): ?DateTimeImmutable
{
    if ($value === '') {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        throw new InvalidArgumentException('Enter a valid date and time.');
    }

    return $date;
}

function recruiter_status_label(string $status): string
{
    return match ($status) {
        'under_review' => 'Under review',
        'shortlisted' => 'Shortlisted',
        'interview' => 'Interview',
        'selected' => 'Selected',
        'rejected' => 'Not selected',
        'withdrawn' => 'Withdrawn',
        'published' => 'Published',
        'paused' => 'Paused',
        'closed' => 'Closed',
        'scheduled' => 'Scheduled',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        'rescheduled' => 'Rescheduled',
        default => ucfirst(str_replace('_', ' ', $status)),
    };
}

try {
    $db = Database::connection();
} catch (Throwable $exception) {
    error_log('Recruiter database connection failure: ' . $exception->getMessage());
    http_response_code(503);
    $appCssVersion = asset_version();
    $recruiterCssVersion = asset_version();
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Recruiter workspace unavailable | SVIET</title>
        <link rel="stylesheet" href="assets/css/app.css?v=<?= recruiter_h($appCssVersion) ?>">
        <link rel="stylesheet" href="assets/css/recruiter.css?v=<?= recruiter_h($recruiterCssVersion) ?>">
    </head>
    <body class="recruiter-portal">
        <main class="rec-main rec-unavailable">
            <p class="rec-eyebrow">SVIET · RECRUITER WORKSPACE</p>
            <section class="rec-card">
                <h1>Workspace temporarily unavailable</h1>
                <p>We couldn't load your recruiter workspace. Please try again shortly.</p>
                <a class="rec-button rec-button--primary" href="index.php?page=dashboard">Back to the portal</a>
            </section>
        </main>
    </body>
    </html>
    <?php
    exit;
}

$csrf = Security::csrfToken();
$section = recruiter_section(recruiter_get('section'));
$flash = $_SESSION['recruiter_flash'] ?? null;
unset($_SESSION['recruiter_flash']);
$message = is_array($flash) && ($flash['kind'] ?? '') === 'success' ? (string) ($flash['message'] ?? '') : '';
$error = is_array($flash) && ($flash['kind'] ?? '') === 'error' ? (string) ($flash['message'] ?? '') : '';
$company = null;
$companyLoadError = false;

try {
    $company = recruiter_company($db, (int) $user['id']);
} catch (Throwable $exception) {
    error_log('Recruiter company profile query failure: ' . $exception->getMessage());
    $companyLoadError = true;
}

$applicationTransitions = [
    'submitted' => ['under_review', 'rejected'],
    'under_review' => ['shortlisted', 'rejected'],
    'shortlisted' => ['interview', 'rejected'],
    'interview' => ['selected', 'rejected'],
    'selected' => [],
    'rejected' => [],
    'withdrawn' => [],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['_csrf'] ?? null;
    if (!is_string($postedToken) || !Security::verifyCsrf($postedToken)) {
        http_response_code(419);
        $error = 'Your session expired. Refresh the page and try again.';
    } else {
        $action = recruiter_post('action');
        $returnSection = recruiter_section(recruiter_post('return_section'), match ($action) {
            'save_company' => 'company',
            'save_job' => 'jobs',
            'change_application' => 'applicants',
            'schedule_interview', 'cancel_interview', 'complete_interview', 'reschedule_interview' => 'interviews',
            default => 'overview',
        });

        try {
            if ($action === 'save_company') {
                $companyName = recruiter_post('name');
                $website = recruiter_post('website');
                $industry = recruiter_post('industry');
                $description = recruiter_post('description');
                $designation = recruiter_post('designation');

                if (
                    $companyName === '' ||
                    mb_strlen($companyName) > 180 ||
                    mb_strlen($website) > 255 ||
                    mb_strlen($industry) > 120 ||
                    mb_strlen($description) > 4000 ||
                    mb_strlen($designation) > 120 ||
                    ($website !== '' && !filter_var($website, FILTER_VALIDATE_URL))
                ) {
                    throw new InvalidArgumentException('Check the company details and enter a valid website URL.');
                }

                $db->beginTransaction();
                $ensureProfileStatement = $db->prepare(
                    'INSERT INTO recruiter_profiles (user_id, company_id, designation) VALUES (?, NULL, NULL) '
                    . 'ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)',
                );
                $ensureProfileStatement->execute([(int) $user['id']]);
                $profileStatement = $db->prepare('SELECT company_id FROM recruiter_profiles WHERE user_id = ? FOR UPDATE');
                $profileStatement->execute([(int) $user['id']]);
                $recruiterProfile = $profileStatement->fetch(PDO::FETCH_ASSOC) ?: null;
                $existingCompanyId = recruiter_positive_id($recruiterProfile['company_id'] ?? null);
                if ($existingCompanyId > 0) {
                    $statement = $db->prepare(
                        'SELECT name AS company_name, website, industry, description, approval_status FROM companies WHERE id = ? FOR UPDATE',
                    );
                    $statement->execute([$existingCompanyId]);
                    $currentCompany = $statement->fetch(PDO::FETCH_ASSOC);
                    if (!$currentCompany) {
                        throw new InvalidArgumentException('Your company profile could not be found. Please contact the Training & Placement Cell.');
                    }
                    $materialChange =
                        trim((string) ($currentCompany['company_name'] ?? '')) !== $companyName ||
                        trim((string) ($currentCompany['website'] ?? '')) !== $website ||
                        trim((string) ($currentCompany['industry'] ?? '')) !== $industry ||
                        trim((string) ($currentCompany['description'] ?? '')) !== $description;
                    $approvalStatus =
                        ($currentCompany['approval_status'] ?? '') === 'approved' && !$materialChange
                            ? 'approved'
                            : 'pending';

                    $statement = $db->prepare(
                        'UPDATE companies SET name = ?, website = ?, industry = ?, description = ?, approval_status = ? WHERE id = ?',
                    );
                    $statement->execute([
                        $companyName,
                        $website !== '' ? $website : null,
                        $industry !== '' ? $industry : null,
                        $description !== '' ? $description : null,
                        $approvalStatus,
                        $existingCompanyId,
                    ]);
                    $companyId = $existingCompanyId;
                } else {
                    $approvalStatus = 'pending';
                    $statement = $db->prepare(
                        'INSERT INTO companies (name, website, industry, description, approval_status) VALUES (?, ?, ?, ?, ?)',
                    );
                    $statement->execute([
                        $companyName,
                        $website !== '' ? $website : null,
                        $industry !== '' ? $industry : null,
                        $description !== '' ? $description : null,
                        $approvalStatus,
                    ]);
                    $companyId = (int) $db->lastInsertId();
                }

                $statement = $db->prepare(
                    'INSERT INTO recruiter_profiles (user_id, company_id, designation) VALUES (?, ?, ?) '
                    . 'ON DUPLICATE KEY UPDATE company_id = VALUES(company_id), designation = VALUES(designation)',
                );
                $statement->execute([
                    (int) $user['id'],
                    $companyId,
                    $designation !== '' ? $designation : null,
                ]);
                recruiter_audit($db, (int) $user['id'], 'company.profile_saved', 'company', $companyId, [
                    'approval_status' => $approvalStatus,
                ]);
                $db->commit();

                $message = $approvalStatus === 'approved'
                    ? 'Company profile saved. Its approval remains active.'
                    : 'Company profile saved and sent to the Training & Placement Cell for review.';
                recruiter_flash_redirect('company', 'success', $message);
            }

            if ($action === 'save_job') {
                $companyId = recruiter_positive_id($company['company_id'] ?? null);
                if ($companyId < 1) {
                    throw new InvalidArgumentException('Complete your company profile before preparing a job.');
                }

                $jobId = recruiter_positive_id($_POST['job_id'] ?? null);
                $title = recruiter_post('title');
                $description = recruiter_post('description');
                $location = recruiter_post('location');
                $employmentType = recruiter_post('employment_type');
                $minimumCgpa = recruiter_post('min_cgpa');
                $eligibleBranch = recruiter_post('eligible_branch');
                $graduationYear = recruiter_post('graduation_year');
                $closingValue = recruiter_post('closes_at');
                $status = recruiter_post('status') ?: 'draft';

                if (
                    $title === '' ||
                    mb_strlen($title) > 180 ||
                    $description === '' ||
                    mb_strlen($description) > 12000 ||
                    mb_strlen($location) > 180 ||
                    mb_strlen($employmentType) > 80 ||
                    mb_strlen($eligibleBranch) > 120 ||
                    !is_numeric($minimumCgpa) ||
                    (float) $minimumCgpa < 0 ||
                    (float) $minimumCgpa > 10 ||
                    ($graduationYear !== '' &&
                        (!ctype_digit($graduationYear) || (int) $graduationYear < 2000 || (int) $graduationYear > 2150)) ||
                    !in_array($status, ['draft', 'published', 'paused', 'closed'], true)
                ) {
                    throw new InvalidArgumentException('Check the job title, description, eligibility details, and status.');
                }

                $closingDate = recruiter_parse_datetime($closingValue);
                if ($status === 'published' && $closingDate && $closingDate <= new DateTimeImmutable()) {
                    throw new InvalidArgumentException('A published job needs a future closing date.');
                }

                $db->beginTransaction();
                $companyStatement = $db->prepare(
                    'SELECT c.approval_status FROM recruiter_profiles rp JOIN companies c ON c.id = rp.company_id '
                    . 'WHERE rp.user_id = ? AND c.id = ? FOR UPDATE',
                );
                $companyStatement->execute([(int) $user['id'], $companyId]);
                $companyApprovalStatus = $companyStatement->fetchColumn();
                if ($companyApprovalStatus === false) {
                    throw new InvalidArgumentException('Your company profile could not be found. Please refresh and try again.');
                }
                if ($status === 'published' && $companyApprovalStatus !== 'approved') {
                    throw new InvalidArgumentException('Your company must be approved before you can publish a job. Drafts can still be prepared.');
                }

                $existingJob = null;
                if ($jobId > 0) {
                    $statement = $db->prepare('SELECT id, status FROM jobs WHERE id = ? AND company_id = ? FOR UPDATE');
                    $statement->execute([$jobId, $companyId]);
                    $existingJob = $statement->fetch(PDO::FETCH_ASSOC);
                    if (!$existingJob) {
                        throw new InvalidArgumentException('That job could not be found in your company workspace.');
                    }
                }

                $closingDateValue = $closingDate?->format('Y-m-d H:i:s');
                $jobValues = [
                    $title,
                    $description,
                    $location !== '' ? $location : null,
                    $employmentType !== '' ? $employmentType : null,
                    (float) $minimumCgpa,
                    $eligibleBranch !== '' ? $eligibleBranch : null,
                    $graduationYear !== '' ? (int) $graduationYear : null,
                    $closingDateValue,
                    $status,
                ];

                if ($existingJob) {
                    $statement = $db->prepare(
                        'UPDATE jobs SET title = ?, description = ?, location = ?, employment_type = ?, min_cgpa = ?, eligible_branch = ?, graduation_year = ?, closes_at = ?, status = ? WHERE id = ? AND company_id = ?',
                    );
                    $statement->execute([...$jobValues, $jobId, $companyId]);
                    recruiter_audit($db, (int) $user['id'], 'job.updated', 'job', $jobId, [
                        'from' => $existingJob['status'],
                        'to' => $status,
                    ]);
                    $message = 'Job changes saved.';
                } else {
                    $statement = $db->prepare(
                        'INSERT INTO jobs (company_id, title, description, location, employment_type, min_cgpa, eligible_branch, graduation_year, closes_at, status) '
                        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    );
                    $statement->execute([$companyId, ...$jobValues]);
                    $jobId = (int) $db->lastInsertId();
                    recruiter_audit($db, (int) $user['id'], 'job.created', 'job', $jobId, ['status' => $status]);
                    $message = $status === 'published' ? 'Job published to eligible students.' : 'Draft job saved.';
                }
                $db->commit();
                recruiter_flash_redirect('jobs', 'success', $message);
            }

            if ($action === 'change_application') {
                $applicationId = recruiter_positive_id($_POST['application_id'] ?? null);
                $nextStatus = recruiter_post('status');
                $note = recruiter_post('note');
                if ($applicationId < 1 || mb_strlen($note) > 500) {
                    throw new InvalidArgumentException('Check the application and keep the note under 500 characters.');
                }

                $db->beginTransaction();
                $statement = $db->prepare(
                    'SELECT a.id, a.student_id, a.status, j.title FROM applications a '
                    . 'JOIN jobs j ON j.id = a.job_id WHERE a.id = ? AND j.company_id = ? FOR UPDATE',
                );
                $statement->execute([$applicationId, recruiter_positive_id($company['company_id'] ?? null)]);
                $application = $statement->fetch(PDO::FETCH_ASSOC);
                if (!$application) {
                    throw new InvalidArgumentException('That application is not available in your company workspace.');
                }

                $currentStatus = (string) $application['status'];
                $allowedNext = $applicationTransitions[$currentStatus] ?? [];
                if (!in_array($nextStatus, $allowedNext, true)) {
                    throw new InvalidArgumentException('Choose a valid next step for this application. Completed or withdrawn applications cannot be changed.');
                }

                $ctcRaw = recruiter_post('ctc_lpa');
                $ctc = null;
                if ($ctcRaw !== '') {
                    if (!is_numeric($ctcRaw) || (float) $ctcRaw < 0 || (float) $ctcRaw > 500) {
                        throw new InvalidArgumentException('Enter the offered CTC in LPA, between 0 and 500.');
                    }
                    $ctc = number_format((float) $ctcRaw, 2, '.', '');
                }

                $db->prepare('UPDATE applications SET status = ? WHERE id = ?')->execute([$nextStatus, $applicationId]);
                $history = $db->prepare(
                    'INSERT INTO application_status_history (application_id, changed_by, old_status, new_status, note) VALUES (?, ?, ?, ?, ?)',
                );
                $history->execute([
                    $applicationId,
                    (int) $user['id'],
                    $currentStatus,
                    $nextStatus,
                    $note !== '' ? $note : 'Updated by recruiter',
                ]);
                $label = recruiter_status_label($nextStatus);
                $offerSentence = '';
                if ($nextStatus === 'selected' && recruiter_table_exists($db, 'offers')) {
                    $existing = $db->prepare('SELECT id FROM offers WHERE application_id = ? LIMIT 1 FOR UPDATE');
                    $existing->execute([$applicationId]);
                    $offerId = (int) ($existing->fetchColumn() ?: 0);
                    if ($offerId > 0) {
                        $db->prepare(
                            "UPDATE offers SET ctc_lpa = COALESCE(?, ctc_lpa), offer_date = COALESCE(offer_date, CURDATE()), status = IF(status IN ('declined', 'revoked'), 'offered', status) WHERE id = ?",
                        )->execute([$ctc, $offerId]);
                        recruiter_audit($db, (int) $user['id'], 'offer.updated', 'offer', $offerId, ['ctc_lpa' => $ctc]);
                    } else {
                        $db->prepare(
                            "INSERT INTO offers (application_id, ctc_lpa, offer_date, status, created_by) VALUES (?, ?, CURDATE(), 'offered', ?)",
                        )->execute([$applicationId, $ctc, (int) $user['id']]);
                        $offerId = (int) $db->lastInsertId();
                        recruiter_audit($db, (int) $user['id'], 'offer.created', 'offer', $offerId, ['ctc_lpa' => $ctc]);
                    }
                    if ($ctc !== null) {
                        $offerSentence = ' The recorded CTC is ' . rtrim(rtrim($ctc, '0'), '.') . ' LPA.';
                    }
                }
                recruiter_notify(
                    $db,
                    (int) $application['student_id'],
                    $nextStatus === 'selected' ? 'You have been selected' : 'Application status updated',
                    'Your application for ' . $application['title'] . ' is now ' . $label . '.' . $offerSentence,
                );
                recruiter_audit($db, (int) $user['id'], 'application.status_changed', 'application', $applicationId, [
                    'from' => $currentStatus,
                    'to' => $nextStatus,
                    'ctc_lpa' => $ctc,
                ]);
                $db->commit();
                recruiter_flash_redirect('applicants', 'success', 'Application moved to ' . strtolower($label) . '.');
            }

            if ($action === 'schedule_interview') {
                $applicationId = recruiter_positive_id($_POST['application_id'] ?? null);
                $whenValue = recruiter_post('scheduled_at');
                $mode = recruiter_post('mode');
                $locationOrLink = recruiter_post('location_or_link');
                $scheduledAt = recruiter_parse_datetime($whenValue);
                if (
                    $applicationId < 1 ||
                    !$scheduledAt ||
                    $scheduledAt <= new DateTimeImmutable() ||
                    mb_strlen($mode) > 60 ||
                    mb_strlen($locationOrLink) > 500
                ) {
                    throw new InvalidArgumentException('Enter a valid future interview time and details.');
                }

                $db->beginTransaction();
                $statement = $db->prepare(
                    'SELECT a.id, a.student_id, a.status, j.title FROM applications a '
                    . 'JOIN jobs j ON j.id = a.job_id WHERE a.id = ? AND j.company_id = ? FOR UPDATE',
                );
                $statement->execute([$applicationId, recruiter_positive_id($company['company_id'] ?? null)]);
                $application = $statement->fetch(PDO::FETCH_ASSOC);
                if (!$application || !in_array($application['status'], ['shortlisted', 'interview'], true)) {
                    throw new InvalidArgumentException('Only shortlisted candidates can be scheduled for interview.');
                }
                $activeInterview = $db->prepare(
                    "SELECT id FROM interviews WHERE application_id = ? AND status = 'scheduled' AND scheduled_at >= NOW() LIMIT 1 FOR UPDATE",
                );
                $activeInterview->execute([$applicationId]);
                if ($activeInterview->fetchColumn()) {
                    throw new InvalidArgumentException('This candidate already has a scheduled interview. Use the reschedule action instead.');
                }

                $statement = $db->prepare(
                    "INSERT INTO interviews (application_id, scheduled_by, scheduled_at, mode, location_or_link, status) VALUES (?, ?, ?, ?, ?, 'scheduled')",
                );
                $statement->execute([
                    $applicationId,
                    (int) $user['id'],
                    $scheduledAt->format('Y-m-d H:i:s'),
                    $mode !== '' ? $mode : null,
                    $locationOrLink !== '' ? $locationOrLink : null,
                ]);
                if ($application['status'] !== 'interview') {
                    $db->prepare('UPDATE applications SET status = ? WHERE id = ?')->execute(['interview', $applicationId]);
                    $db->prepare(
                        'INSERT INTO application_status_history (application_id, changed_by, old_status, new_status, note) VALUES (?, ?, ?, ?, ?)',
                    )->execute([$applicationId, (int) $user['id'], $application['status'], 'interview', 'Interview scheduled']);
                }
                recruiter_notify(
                    $db,
                    (int) $application['student_id'],
                    'Interview scheduled',
                    'An interview for ' . $application['title'] . ' has been scheduled for ' . $scheduledAt->format('M j, Y g:i A T') . '.',
                );
                recruiter_audit($db, (int) $user['id'], 'interview.scheduled', 'application', $applicationId);
                $db->commit();
                recruiter_flash_redirect('interviews', 'success', 'Interview scheduled and the student notified.');
            }

            if (in_array($action, ['cancel_interview', 'complete_interview', 'reschedule_interview'], true)) {
                $interviewId = recruiter_positive_id($_POST['interview_id'] ?? null);
                if ($interviewId < 1) {
                    throw new InvalidArgumentException('Select a valid interview.');
                }

                $rescheduledAt = null;
                $mode = recruiter_post('mode');
                $locationOrLink = recruiter_post('location_or_link');
                if ($action === 'reschedule_interview') {
                    $rescheduledAt = recruiter_parse_datetime(recruiter_post('scheduled_at'));
                    if (
                        !$rescheduledAt ||
                        $rescheduledAt <= new DateTimeImmutable() ||
                        mb_strlen($mode) > 60 ||
                        mb_strlen($locationOrLink) > 500
                    ) {
                        throw new InvalidArgumentException('Enter a valid future interview time and details.');
                    }
                }

                $db->beginTransaction();
                $companyId = recruiter_positive_id($company['company_id'] ?? null);
                $lookup = $db->prepare(
                    'SELECT i.application_id FROM interviews i JOIN applications a ON a.id = i.application_id '
                    . 'JOIN jobs j ON j.id = a.job_id WHERE i.id = ? AND j.company_id = ? LIMIT 1',
                );
                $lookup->execute([$interviewId, $companyId]);
                $applicationId = recruiter_positive_id($lookup->fetchColumn());
                if ($applicationId < 1) {
                    throw new InvalidArgumentException('That interview is not available in your company workspace.');
                }

                $applicationLock = $db->prepare('SELECT id FROM applications WHERE id = ? FOR UPDATE');
                $applicationLock->execute([$applicationId]);
                if (!$applicationLock->fetchColumn()) {
                    throw new InvalidArgumentException('The application for this interview could not be found.');
                }

                $statement = $db->prepare(
                    'SELECT i.id, i.application_id, i.scheduled_at, i.mode, i.location_or_link, i.status, '
                    . 'a.student_id, j.title FROM interviews i JOIN applications a ON a.id = i.application_id '
                    . 'JOIN jobs j ON j.id = a.job_id WHERE i.id = ? AND j.company_id = ? FOR UPDATE',
                );
                $statement->execute([$interviewId, $companyId]);
                $interview = $statement->fetch(PDO::FETCH_ASSOC);
                if (!$interview || $interview['status'] !== 'scheduled') {
                    throw new InvalidArgumentException('Only scheduled interviews can be changed.');
                }
                if ($action === 'reschedule_interview') {
                    $activeInterview = $db->prepare(
                        "SELECT id FROM interviews WHERE application_id = ? AND status = 'scheduled' AND scheduled_at >= NOW() AND id <> ? LIMIT 1 FOR UPDATE",
                    );
                    $activeInterview->execute([(int) $interview['application_id'], $interviewId]);
                    if ($activeInterview->fetchColumn()) {
                        throw new InvalidArgumentException('This candidate already has another scheduled interview.');
                    }
                }

                if ($action === 'cancel_interview') {
                    $db->prepare('UPDATE interviews SET status = ? WHERE id = ?')->execute(['cancelled', $interviewId]);
                    recruiter_notify(
                        $db,
                        (int) $interview['student_id'],
                        'Interview cancelled',
                        'The interview for ' . $interview['title'] . ' has been cancelled by the recruiter.',
                    );
                    recruiter_audit($db, (int) $user['id'], 'interview.cancelled', 'interview', $interviewId);
                    $successMessage = 'Interview cancelled and the student notified.';
                } elseif ($action === 'complete_interview') {
                    if (new DateTimeImmutable((string) $interview['scheduled_at']) > new DateTimeImmutable()) {
                        throw new InvalidArgumentException('An upcoming interview cannot be marked complete.');
                    }
                    $db->prepare('UPDATE interviews SET status = ? WHERE id = ?')->execute(['completed', $interviewId]);
                    recruiter_audit($db, (int) $user['id'], 'interview.completed', 'interview', $interviewId);
                    $successMessage = 'Interview marked complete.';
                } else {
                    $db->prepare('UPDATE interviews SET status = ? WHERE id = ?')->execute(['rescheduled', $interviewId]);
                    $statement = $db->prepare(
                        "INSERT INTO interviews (application_id, scheduled_by, scheduled_at, mode, location_or_link, status) VALUES (?, ?, ?, ?, ?, 'scheduled')",
                    );
                    $statement->execute([
                        (int) $interview['application_id'],
                        (int) $user['id'],
                        $rescheduledAt->format('Y-m-d H:i:s'),
                        $mode !== '' ? $mode : $interview['mode'],
                        $locationOrLink !== '' ? $locationOrLink : $interview['location_or_link'],
                    ]);
                    recruiter_notify(
                        $db,
                        (int) $interview['student_id'],
                        'Interview rescheduled',
                        'The interview for ' . $interview['title'] . ' has been rescheduled to ' . $rescheduledAt->format('M j, Y g:i A T') . '.',
                    );
                    recruiter_audit($db, (int) $user['id'], 'interview.rescheduled', 'interview', $interviewId);
                    $successMessage = 'Interview rescheduled and the student notified.';
                }

                $db->commit();
                recruiter_flash_redirect('interviews', 'success', $successMessage);
            }

            throw new InvalidArgumentException('Choose a valid recruiter action.');
        } catch (InvalidArgumentException $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            recruiter_flash_redirect($returnSection, 'error', $exception->getMessage());
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Recruiter action failure: ' . $exception->getMessage());
            recruiter_flash_redirect($returnSection, 'error', 'We could not complete that action. Please try again shortly.');
        }
    }
}

try {
    $company = recruiter_company($db, (int) $user['id']);
} catch (Throwable $exception) {
    error_log('Recruiter company refresh failure: ' . $exception->getMessage());
    $company = null;
    $companyLoadError = true;
}
$companyId = recruiter_positive_id($company['company_id'] ?? null);
$companyStatus = (string) ($company['approval_status'] ?? 'not_submitted');
$companyStatusLabel = match ($companyStatus) {
    'approved' => 'Approved',
    'pending' => 'Under T&P review',
    'rejected' => 'Changes requested',
    default => 'Profile not submitted',
};

$statistics = [
    'live_jobs' => 0,
    'new_applicants' => 0,
    'active_pipeline' => 0,
    'upcoming_interviews' => 0,
];
$recentApplications = [];
if ($companyId > 0 && !$companyLoadError) {
    try {
        $statement = $db->prepare(
            "SELECT COUNT(*) FROM jobs j JOIN companies c ON c.id = j.company_id WHERE j.company_id = ? AND j.status = 'published' AND c.approval_status = 'approved' AND (j.closes_at IS NULL OR j.closes_at >= NOW())",
        );
        $statement->execute([$companyId]);
        $statistics['live_jobs'] = (int) $statement->fetchColumn();

        $statement = $db->prepare(
            "SELECT COUNT(*) FROM applications a JOIN jobs j ON j.id = a.job_id WHERE j.company_id = ? AND a.status = 'submitted'",
        );
        $statement->execute([$companyId]);
        $statistics['new_applicants'] = (int) $statement->fetchColumn();

        $statement = $db->prepare(
            "SELECT COUNT(*) FROM applications a JOIN jobs j ON j.id = a.job_id WHERE j.company_id = ? AND a.status IN ('under_review', 'shortlisted', 'interview')",
        );
        $statement->execute([$companyId]);
        $statistics['active_pipeline'] = (int) $statement->fetchColumn();

        $statement = $db->prepare(
            "SELECT COUNT(*) FROM interviews i JOIN applications a ON a.id = i.application_id JOIN jobs j ON j.id = a.job_id WHERE j.company_id = ? AND i.status = 'scheduled' AND i.scheduled_at >= NOW()",
        );
        $statement->execute([$companyId]);
        $statistics['upcoming_interviews'] = (int) $statement->fetchColumn();

        $statement = $db->prepare(
            'SELECT a.id, a.status, a.applied_at, u.name AS student_name, j.title '
            . 'FROM applications a JOIN jobs j ON j.id = a.job_id JOIN users u ON u.id = a.student_id '
            . 'WHERE j.company_id = ? ORDER BY a.applied_at DESC LIMIT 6',
        );
        $statement->execute([$companyId]);
        $recentApplications = $statement->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $exception) {
        error_log('Recruiter overview query failure: ' . $exception->getMessage());
        $companyLoadError = true;
    }
}

$jobStatusFilter = recruiter_get('status');
if (!in_array($jobStatusFilter, ['all', 'draft', 'published', 'paused', 'closed'], true)) {
    $jobStatusFilter = 'all';
}
$jobSearch = mb_substr(recruiter_get('q'), 0, 80);
$jobPage = max(1, recruiter_positive_id($_GET['job_page'] ?? 1));
$jobPageSize = 9;
$jobTotal = 0;
$jobPages = 1;
$jobs = [];
$editJob = null;
$editJobId = recruiter_positive_id($_GET['edit'] ?? null);
if ($companyId > 0 && $section === 'jobs' && !$companyLoadError) {
    try {
        $conditions = ['company_id = ?'];
        $parameters = [$companyId];
        if ($jobStatusFilter !== 'all') {
            $conditions[] = 'status = ?';
            $parameters[] = $jobStatusFilter;
        }
        if ($jobSearch !== '') {
            $conditions[] = '(title LIKE ? OR location LIKE ?)';
            $parameters[] = '%' . $jobSearch . '%';
            $parameters[] = '%' . $jobSearch . '%';
        }
        $where = implode(' AND ', $conditions);
        $statement = $db->prepare('SELECT COUNT(*) FROM jobs WHERE ' . $where);
        $statement->execute($parameters);
        $jobTotal = (int) $statement->fetchColumn();
        $jobPages = max(1, (int) ceil($jobTotal / $jobPageSize));
        $jobPage = min($jobPage, $jobPages);
        $offset = ($jobPage - 1) * $jobPageSize;
        $statement = $db->prepare(
            'SELECT j.*, (SELECT COUNT(*) FROM applications a WHERE a.job_id = j.id) AS applicant_count '
            . 'FROM jobs j WHERE ' . $where . ' ORDER BY j.updated_at DESC LIMIT ' . $jobPageSize . ' OFFSET ' . $offset,
        );
        $statement->execute($parameters);
        $jobs = $statement->fetchAll(PDO::FETCH_ASSOC);

        if ($editJobId > 0) {
            $statement = $db->prepare('SELECT * FROM jobs WHERE id = ? AND company_id = ? LIMIT 1');
            $statement->execute([$editJobId, $companyId]);
            $editJob = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    } catch (Throwable $exception) {
        error_log('Recruiter jobs query failure: ' . $exception->getMessage());
        $companyLoadError = true;
    }
}

$applicationStatusFilter = recruiter_get('status');
if (!in_array($applicationStatusFilter, ['all', 'submitted', 'under_review', 'shortlisted', 'interview', 'selected', 'rejected', 'withdrawn'], true)) {
    $applicationStatusFilter = 'all';
}
$applicationSearch = mb_substr(recruiter_get('q'), 0, 80);
$applicationPage = max(1, recruiter_positive_id($_GET['app_page'] ?? 1));
$applicationPageSize = 12;
$applicationTotal = 0;
$applicationPages = 1;
$applications = [];
$applicationHistory = [];
if ($companyId > 0 && $section === 'applicants' && !$companyLoadError) {
    try {
        $conditions = ['j.company_id = ?'];
        $parameters = [$companyId];
        if ($applicationStatusFilter !== 'all') {
            $conditions[] = 'a.status = ?';
            $parameters[] = $applicationStatusFilter;
        }
        if ($applicationSearch !== '') {
            $conditions[] = '(u.name LIKE ? OR u.email LIKE ? OR j.title LIKE ?)';
            $like = '%' . $applicationSearch . '%';
            array_push($parameters, $like, $like, $like);
        }
        $where = implode(' AND ', $conditions);
        $from = ' FROM applications a JOIN jobs j ON j.id = a.job_id JOIN users u ON u.id = a.student_id '
            . 'LEFT JOIN student_profiles p ON p.user_id = u.id';
        $statement = $db->prepare('SELECT COUNT(*)' . $from . ' WHERE ' . $where);
        $statement->execute($parameters);
        $applicationTotal = (int) $statement->fetchColumn();
        $applicationPages = max(1, (int) ceil($applicationTotal / $applicationPageSize));
        $applicationPage = min($applicationPage, $applicationPages);
        $offset = ($applicationPage - 1) * $applicationPageSize;
        $statement = $db->prepare(
            'SELECT a.id, a.student_id, a.status, a.applied_at, a.cover_note, u.name AS student_name, u.email, '
            . 'p.branch, p.cgpa, p.graduation_year, j.title, j.id AS job_id, '
            . "EXISTS (SELECT 1 FROM interviews future_i WHERE future_i.application_id = a.id AND future_i.status = 'scheduled' AND future_i.scheduled_at >= NOW()) AS has_upcoming_interview"
            . $from . ' WHERE ' . $where
            . ' ORDER BY a.applied_at DESC LIMIT ' . $applicationPageSize . ' OFFSET ' . $offset,
        );
        $statement->execute($parameters);
        $applications = $statement->fetchAll(PDO::FETCH_ASSOC);

        if ($applications) {
            $applicationIds = array_map('intval', array_column($applications, 'id'));
            $placeholders = implode(',', array_fill(0, count($applicationIds), '?'));
            $statement = $db->prepare(
                'SELECT application_id, old_status, new_status, note, created_at FROM application_status_history '
                . 'WHERE application_id IN (' . $placeholders . ') ORDER BY created_at DESC',
            );
            $statement->execute($applicationIds);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $event) {
                $applicationId = (int) $event['application_id'];
                if (count($applicationHistory[$applicationId] ?? []) < 3) {
                    $applicationHistory[$applicationId][] = $event;
                }
            }
        }
    } catch (Throwable $exception) {
        error_log('Recruiter applications query failure: ' . $exception->getMessage());
        $companyLoadError = true;
    }
}

$interviewFilter = recruiter_get('when');
if (!in_array($interviewFilter, ['upcoming', 'past', 'all'], true)) {
    $interviewFilter = 'upcoming';
}
$interviews = [];
$interviewCandidates = [];
$scheduleApplicationId = recruiter_positive_id($_GET['application'] ?? null);
if ($companyId > 0 && $section === 'interviews' && !$companyLoadError) {
    try {
        $candidateStatement = $db->prepare(
            "SELECT a.id, a.status, u.name AS student_name, j.title FROM applications a "
            . "JOIN jobs j ON j.id = a.job_id JOIN users u ON u.id = a.student_id "
            . "WHERE j.company_id = ? AND a.status IN ('shortlisted', 'interview') "
            . "AND NOT EXISTS (SELECT 1 FROM interviews i WHERE i.application_id = a.id AND i.status = 'scheduled' AND i.scheduled_at >= NOW()) "
            . 'ORDER BY a.applied_at DESC',
        );
        $candidateStatement->execute([$companyId]);
        $interviewCandidates = $candidateStatement->fetchAll(PDO::FETCH_ASSOC);

        $interviewConditions = ['j.company_id = ?'];
        $interviewParameters = [$companyId];
        if ($interviewFilter === 'upcoming') {
            $interviewConditions[] = "i.status = 'scheduled' AND i.scheduled_at >= NOW()";
        } elseif ($interviewFilter === 'past') {
            $interviewConditions[] = "(i.status <> 'scheduled' OR i.scheduled_at < NOW())";
        }
        $statement = $db->prepare(
            'SELECT i.id, i.application_id, i.scheduled_at, i.mode, i.location_or_link, i.status, '
            . 'a.status AS application_status, u.name AS student_name, j.title '
            . 'FROM interviews i JOIN applications a ON a.id = i.application_id '
            . 'JOIN jobs j ON j.id = a.job_id JOIN users u ON u.id = a.student_id '
            . 'WHERE ' . implode(' AND ', $interviewConditions) . ' ORDER BY i.scheduled_at '
            . ($interviewFilter === 'past' ? 'DESC' : 'ASC') . ' LIMIT 100',
        );
        $statement->execute($interviewParameters);
        $interviews = $statement->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $exception) {
        error_log('Recruiter interviews query failure: ' . $exception->getMessage());
        $companyLoadError = true;
    }
}

$pageTitles = [
    'overview' => ['Overview', 'Your hiring activity, next steps, and recent applicants.'],
    'company' => ['Company profile', 'Manage your organization details and review status.'],
    'jobs' => ['Your jobs', 'Create, update, and track opportunities for SVIET students.'],
    'applicants' => ['Applicants', 'Review candidates and move applications through your hiring pipeline.'],
    'interviews' => ['Interviews', 'Schedule, reschedule, and follow up on candidate interviews.'],
];
[$pageTitle, $pageDescription] = $pageTitles[$section];
$appCssVersion = asset_version();
$recruiterCssVersion = asset_version();
$recruiterNavJsVersion = asset_version();

require __DIR__ . '/views/recruiter/layout-start.php';
require __DIR__ . '/views/recruiter/' . $section . '.php';
require __DIR__ . '/views/recruiter/layout-end.php';
