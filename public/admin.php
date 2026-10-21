<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Security;
use App\Services\AdminImportService;

require dirname(__DIR__) . '/bootstrap/app.php';
Security::requireRole(['tpo','admin']);

$user = Security::currentUser();

function admin_h(?string $value): string
{
    return Security::escape($value);
}

function admin_post(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

function admin_get(string $key): string
{
    $value = $_GET[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

function admin_id(mixed $value): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT);
    return $id !== false && $id > 0 ? (int) $id : 0;
}

/** @return list<int> */
function admin_ids(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }
    $ids = [];
    foreach ($value as $candidate) {
        $id = admin_id($candidate);
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    return array_values($ids);
}

function admin_section(string $candidate): string
{
    $sections = ['overview', 'approvals', 'students', 'placements', 'jobs', 'reports', 'comms', 'governance'];
    return in_array($candidate, $sections, true) ? $candidate : 'overview';
}

function admin_audit(PDO $db, int $actorId, string $action, string $entity, ?int $entityId, array $metadata = []): void
{
    $statement = $db->prepare(
        'INSERT INTO audit_logs (actor_id, action, entity_type, entity_id, ip_hash, metadata_json) VALUES (?, ?, ?, ?, ?, ?)',
    );
    $statement->execute([
        $actorId,
        $action,
        $entity,
        $entityId,
        isset($_SERVER['REMOTE_ADDR']) ? hash('sha256', (string) $_SERVER['REMOTE_ADDR']) : null,
        $metadata ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null,
    ]);
}

function admin_notify(PDO $db, int $userId, string $title, string $body): void
{
    $statement = $db->prepare('INSERT INTO notifications (user_id, title, body) VALUES (?, ?, ?)');
    $statement->execute([$userId, $title, $body]);
}

function admin_flash_redirect(string $section, string $kind, string $message, array $query = []): never
{
    $_SESSION['admin_flash'] = ['kind' => $kind, 'message' => $message];
    $query['section'] = admin_section($section);
    header('Location: admin.php?' . http_build_query($query), true, 303);
    exit;
}

function admin_table_exists(PDO $db, string $table): bool
{
    try {
        $statement = $db->prepare('SHOW TABLES LIKE ?');
        $statement->execute([$table]);
        return (bool) $statement->fetchColumn();
    } catch (Throwable $exception) {
        return false;
    }
}

function admin_age_days(?string $datetime): int
{
    if (!$datetime) {
        return 0;
    }
    try {
        $created = new DateTimeImmutable($datetime);
        $diff = $created->diff(new DateTimeImmutable('now'));
        return (int) $diff->days;
    } catch (Throwable $exception) {
        return 0;
    }
}

function admin_age_label(?string $datetime): string
{
    $days = admin_age_days($datetime);
    if ($days <= 0) {
        return 'Today';
    }
    return $days === 1 ? '1 day' : $days . ' days';
}

function admin_money(?string $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    $number = (float) $value;
    return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.') . ' LPA';
}

function admin_status_label(string $status): string
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
        'draft' => 'Draft',
        'offered' => 'Offer sent',
        'accepted' => 'Offer accepted',
        'declined' => 'Offer declined',
        'joined' => 'Joined',
        'revoked' => 'Offer revoked',
        'not_submitted' => 'Not submitted',
        'pending' => 'Pending',
        'approved' => 'Approved',
        'rejected_company' => 'Rejected',
        default => ucfirst(str_replace('_', ' ', $status)),
    };
}

function admin_export_csv(string $filename, array $header, array $rows): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    $handle = fopen('php://output', 'w');
    if ($handle === false) {
        exit;
    }
    if (str_contains($filename, 'students') || str_contains($filename, 'offers') || str_contains($filename, 'report')) {
        fwrite($handle, "\xEF\xBB\xBF");
    }
    fputcsv($handle, $header, ',', '"', '\\');
    foreach ($rows as $row) {
        fputcsv($handle, $row, ',', '"', '\\');
    }
    fclose($handle);
    exit;
}

try {
    $db = Database::connection();
} catch (Throwable $exception) {
    error_log('Admin database connection failure: ' . $exception->getMessage());
    http_response_code(503);
    $appCssVersion = asset_version();
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Admin portal unavailable | SVIET</title>
        <link rel="stylesheet" href="assets/css/app.css?v=<?= admin_h($appCssVersion) ?>">
    </head>
    <body>
        <main class="container" style="padding-block:64px">
            <h1>Control centre temporarily unavailable</h1>
            <p>We couldn't connect to the placement database. Please try again shortly.</p>
            <a class="btn btn-primary" href="index.php?page=dashboard">Back to the portal</a>
        </main>
    </body>
    </html>
    <?php
    exit;
}

$importService = new AdminImportService();
$csrf = Security::csrfToken();
$section = admin_section(admin_get('section'));
$offersReady = admin_table_exists($db, 'offers');

$flash = $_SESSION['admin_flash'] ?? null;
unset($_SESSION['admin_flash']);
$message = is_array($flash) && ($flash['kind'] ?? '') === 'success' ? (string) ($flash['message'] ?? '') : '';
$error = is_array($flash) && ($flash['kind'] ?? '') === 'error' ? (string) ($flash['message'] ?? '') : '';

$userDecisionMap = [
    'approve' => ['status' => 'active', 'label' => 'approved'],
    'reject' => ['status' => 'inactive', 'label' => 'rejected'],
    'suspend' => ['status' => 'suspended', 'label' => 'suspended'],
    'reactivate' => ['status' => 'active', 'label' => 'reactivated'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['_csrf'] ?? null;
    if (!is_string($postedToken) || !Security::verifyCsrf($postedToken)) {
        http_response_code(419);
        $error = 'Your session expired. Refresh the page and try again.';
    } else {
        $action = admin_post('action');
        $returnSection = admin_section(admin_post('return_section') ?: admin_section($section));
        $note = mb_substr(admin_post('note'), 0, 500);

        try {
            if ($action === 'user_decision' || $action === 'users_bulk') {
                $decision = admin_post('decision');
                $requestChanges = $decision === 'request_changes';
                if (!isset($userDecisionMap[$decision]) && !$requestChanges) {
                    throw new InvalidArgumentException('Choose a valid account decision.');
                }
                if ($requestChanges && $note === '') {
                    throw new InvalidArgumentException('Describe what the account holder needs to change.');
                }
                $ids = $action === 'users_bulk' ? admin_ids($_POST['ids'] ?? null) : array_filter([admin_id($_POST['id'] ?? null)]);
                if ($ids === [] && $action === 'users_bulk' && admin_post('bulk_scope') === 'all_pending' && $decision === 'approve') {
                    $ids = $db->query("SELECT id FROM users WHERE status = 'pending' AND role IN ('student', 'recruiter')")->fetchAll(PDO::FETCH_COLUMN);
                    $ids = array_map('intval', $ids);
                }
                if ($ids === []) {
                    throw new InvalidArgumentException('Select at least one account.');
                }

                $db->beginTransaction();
                $select = $db->prepare("SELECT id, name, role, status FROM users WHERE id = ? AND role IN ('student', 'recruiter', 'tpo') FOR UPDATE");
                $update = $db->prepare('UPDATE users SET status = ? WHERE id = ?');
                $changed = 0;
                $skipped = 0;
                foreach ($ids as $id) {
                    $select->execute([$id]);
                    $target = $select->fetch(PDO::FETCH_ASSOC);
                    if (!$target) {
                        $skipped++;
                        continue;
                    }
                    if ($target['role'] === 'tpo' && $user['role'] !== 'admin') {
                        $skipped++;
                        continue;
                    }
                    if ((int) $target['id'] === (int) $user['id']) {
                        $skipped++;
                        continue;
                    }
                    if ($requestChanges) {
                        $update->execute(['pending', $id]);
                        admin_notify(
                            $db,
                            $id,
                            'Action needed on your Campus account',
                            'The Training & Placement Cell reviewed your account and needs a change: ' . $note . ' Update your details, then contact the cell for re-review.',
                        );
                        admin_audit($db, (int) $user['id'], 'user_changes_requested', 'user', $id, ['role' => $target['role'], 'note' => $note]);
                    } else {
                        $mapped = $userDecisionMap[$decision];
                        $update->execute([$mapped['status'], $id]);
                        $body = 'Your Campus account status is now ' . $mapped['status'] . '.';
                        if ($note !== '') {
                            $body .= ' Note from the Training & Placement Cell: ' . $note;
                        }
                        admin_notify($db, $id, 'Account status updated', $body);
                        admin_audit($db, (int) $user['id'], 'user_' . $mapped['label'], 'user', $id, ['role' => $target['role'], 'new_status' => $mapped['status'], 'note' => $note]);
                    }
                    $changed++;
                }
                $db->commit();
                $suffix = $skipped > 0 ? ' ' . $skipped . ' skipped (not permitted).' : '';
                $message = ($requestChanges ? 'Change request sent to ' : 'Updated ') . $changed . ' account' . ($changed === 1 ? '' : 's') . '.' . $suffix;
            } elseif ($action === 'company_decision') {
                $id = admin_id($_POST['id'] ?? null);
                $decision = admin_post('decision');
                if (!in_array($decision, ['approve', 'reject', 'request_changes'], true)) {
                    throw new InvalidArgumentException('Choose a valid company decision.');
                }
                if ($decision === 'request_changes' && $note === '') {
                    throw new InvalidArgumentException('Describe what the company needs to change.');
                }

                $db->beginTransaction();
                $statement = $db->prepare('SELECT id, name, approval_status FROM companies WHERE id = ? FOR UPDATE');
                $statement->execute([$id]);
                $company = $statement->fetch(PDO::FETCH_ASSOC);
                if (!$company) {
                    throw new RuntimeException('Company not found.');
                }
                $status = match ($decision) {
                    'approve' => 'approved',
                    'reject' => 'rejected',
                    default => 'pending',
                };
                $db->prepare('UPDATE companies SET approval_status = ? WHERE id = ?')->execute([$status, $id]);
                $recipients = $db->prepare('SELECT user_id FROM recruiter_profiles WHERE company_id = ?');
                $recipients->execute([$id]);
                $title = $decision === 'request_changes' ? 'Company profile needs changes' : 'Company review completed';
                $body = $decision === 'request_changes'
                    ? 'The Training & Placement Cell reviewed ' . $company['name'] . ' and needs a change: ' . $note . ' Update the company profile, then resubmit for review.'
                    : 'Your company profile was ' . $status . '.' . ($note !== '' ? ' Note: ' . $note : '');
                foreach ($recipients->fetchAll(PDO::FETCH_COLUMN) as $recruiterId) {
                    admin_notify($db, (int) $recruiterId, $title, $body);
                }
                admin_audit($db, (int) $user['id'], 'company_' . $decision, 'company', $id, ['approval_status' => $status, 'note' => $note]);
                $db->commit();
                $message = $decision === 'request_changes' ? 'Change request sent to the company.' : 'Company review saved.';
            } elseif ($action === 'job_decision') {
                $id = admin_id($_POST['id'] ?? null);
                $decision = admin_post('decision');
                if (!in_array($decision, ['publish', 'pause', 'close', 'draft'], true)) {
                    throw new InvalidArgumentException('Choose a valid job decision.');
                }
                $db->beginTransaction();
                $statement = $db->prepare('SELECT j.id, j.status, j.title, c.approval_status FROM jobs j JOIN companies c ON c.id = j.company_id WHERE j.id = ? FOR UPDATE');
                $statement->execute([$id]);
                $job = $statement->fetch(PDO::FETCH_ASSOC);
                if (!$job) {
                    throw new RuntimeException('Job not found.');
                }
                if ($decision === 'publish' && $job['approval_status'] !== 'approved') {
                    throw new RuntimeException('Approve the company before publishing its job.');
                }
                $status = match ($decision) {
                    'publish' => 'published',
                    'pause' => 'paused',
                    'close' => 'closed',
                    default => 'draft',
                };
                $db->prepare('UPDATE jobs SET status = ? WHERE id = ?')->execute([$status, $id]);
                admin_audit($db, (int) $user['id'], 'job_' . $decision, 'job', $id, ['new_status' => $status, 'note' => $note]);
                $db->commit();
                $message = 'Job moderation updated.';
            } elseif ($action === 'announcement') {
                $title = admin_post('title');
                $body = admin_post('body');
                $audience = admin_post('audience');
                if ($title === '' || mb_strlen($title) > 180 || $body === '' || mb_strlen($body) > 10000 || !in_array($audience, ['all', 'students', 'recruiters', 'tpo'], true)) {
                    throw new InvalidArgumentException('Enter a valid title, message and audience.');
                }
                $db->beginTransaction();
                $db->prepare('INSERT INTO announcements (author_id, title, body, audience, published_at) VALUES (?, ?, ?, ?, NOW())')
                    ->execute([(int) $user['id'], $title, $body, $audience]);
                $announcementId = (int) $db->lastInsertId();
                $role = match ($audience) {
                    'students' => 'student',
                    'recruiters' => 'recruiter',
                    'tpo' => 'tpo',
                    default => '',
                };
                if ($role === '') {
                    $recipients = $db->query("SELECT id FROM users WHERE status = 'active' AND role IN ('student', 'recruiter', 'tpo')");
                } else {
                    $recipients = $db->prepare("SELECT id FROM users WHERE status = 'active' AND role = ?");
                    $recipients->execute([$role]);
                }
                $insert = $db->prepare('INSERT INTO notifications (user_id, title, body) VALUES (?, ?, ?)');
                foreach ($recipients->fetchAll(PDO::FETCH_COLUMN) as $recipientId) {
                    $insert->execute([(int) $recipientId, $title, $body]);
                }
                admin_audit($db, (int) $user['id'], 'announcement_published', 'announcement', $announcementId, ['audience' => $audience]);
                $db->commit();
                $message = 'Announcement published and notifications queued.';
            } elseif ($action === 'nudge_profiles') {
                $statement = $db->query(
                    "SELECT u.id FROM users u LEFT JOIN student_profiles sp ON sp.user_id = u.id "
                    . "WHERE u.role = 'student' AND u.status = 'active' AND (sp.user_id IS NULL OR sp.branch IS NULL OR sp.cgpa IS NULL OR sp.graduation_year IS NULL)",
                );
                $ids = $statement->fetchAll(PDO::FETCH_COLUMN);
                $insert = $db->prepare('INSERT INTO notifications (user_id, title, body) VALUES (?, ?, ?)');
                foreach ($ids as $studentId) {
                    $insert->execute([
                        (int) $studentId,
                        'Complete your placement profile',
                        'Recruiters can only shortlist students with a complete profile. Add your branch, CGPA, graduation year and resume in the portal before the next drive.',
                    ]);
                }
                admin_audit($db, (int) $user['id'], 'profile_nudge_sent', 'user', null, ['recipients' => count($ids)]);
                $message = 'Reminder sent to ' . count($ids) . ' student' . (count($ids) === 1 ? '' : 's') . ' with incomplete profiles.';
            } elseif ($action === 'import_preview') {
                if (!isset($_FILES['csv']) || !is_array($_FILES['csv'])) {
                    throw new InvalidArgumentException('Choose a CSV file to import.');
                }
                $file = $_FILES['csv'];
                if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    throw new InvalidArgumentException('The upload failed. Choose a CSV file smaller than 512 KB.');
                }
                if ((int) ($file['size'] ?? 0) > AdminImportService::MAX_BYTES) {
                    throw new InvalidArgumentException('The file is larger than 512 KB. Split it into smaller files.');
                }
                $contents = file_get_contents((string) $file['tmp_name']);
                if ($contents === false) {
                    throw new RuntimeException('The uploaded file could not be read.');
                }
                $parsed = $importService->parse($contents);
                if ($parsed['rows'] === []) {
                    throw new InvalidArgumentException(implode(' ', $parsed['errors']) ?: 'No usable rows were found in that file.');
                }
                $_SESSION['admin_import'] = [
                    'file' => mb_substr(basename((string) $file['name']), 0, 120),
                    'rows' => $parsed['rows'],
                    'created_at' => time(),
                ];
                $valid = count(array_filter($parsed['rows'], static fn (array $row): bool => $row['valid']));
                admin_flash_redirect('students', 'success', 'Parsed ' . count($parsed['rows']) . ' row' . (count($parsed['rows']) === 1 ? '' : 's') . '; ' . $valid . ' ready to import.', ['preview' => '1']);
            } elseif ($action === 'import_cancel') {
                unset($_SESSION['admin_import']);
                admin_flash_redirect('students', 'success', 'Import cancelled. No accounts were created.');
            } elseif ($action === 'import_commit') {
                $staged = $_SESSION['admin_import'] ?? null;
                if (!is_array($staged) || !isset($staged['rows'])) {
                    throw new InvalidArgumentException('That import preview expired. Upload the file again.');
                }
                if (time() - (int) ($staged['created_at'] ?? 0) > 1800) {
                    unset($_SESSION['admin_import']);
                    throw new InvalidArgumentException('That import preview expired. Upload the file again.');
                }

                $db->beginTransaction();
                $emailCheck = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
                $insertUser = $db->prepare("INSERT INTO users (name, email, password_hash, role, status) VALUES (?, ?, ?, ?, 'active')");
                $insertProfile = $db->prepare('INSERT INTO student_profiles (user_id, branch, cgpa, graduation_year) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE branch = VALUES(branch), cgpa = VALUES(cgpa), graduation_year = VALUES(graduation_year)');
                $companyLookup = $db->prepare('SELECT id FROM companies WHERE name = ? LIMIT 1');
                $companyInsert = $db->prepare("INSERT INTO companies (name, approval_status) VALUES (?, 'pending')");
                $profileInsert = $db->prepare('INSERT INTO recruiter_profiles (user_id, company_id, designation) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE company_id = VALUES(company_id), designation = VALUES(designation)');

                $created = [];
                $skipped = [];
                foreach ($staged['rows'] as $row) {
                    if (empty($row['valid'])) {
                        $skipped[] = 'Line ' . (int) $row['line'] . ': ' . implode(' ', (array) $row['errors']);
                        continue;
                    }
                    $values = $row['values'];
                    $emailCheck->execute([$values['email']]);
                    if ($emailCheck->fetchColumn()) {
                        $skipped[] = 'Line ' . (int) $row['line'] . ': ' . $values['email'] . ' already has an account.';
                        continue;
                    }
                    $password = $importService->generatePassword();
                    $insertUser->execute([
                        $values['name'],
                        $values['email'],
                        password_hash($password, PASSWORD_DEFAULT),
                        $values['role'],
                    ]);
                    $newId = (int) $db->lastInsertId();

                    if ($values['role'] === 'student') {
                        $insertProfile->execute([
                            $newId,
                            $values['branch'] !== '' ? $values['branch'] : null,
                            $values['cgpa'] !== '' ? $values['cgpa'] : null,
                            $values['graduation_year'] !== '' ? (int) $values['graduation_year'] : null,
                        ]);
                    } else {
                        $companyLookup->execute([$values['company']]);
                        $companyId = (int) ($companyLookup->fetchColumn() ?: 0);
                        if ($companyId === 0) {
                            $companyInsert->execute([$values['company']]);
                            $companyId = (int) $db->lastInsertId();
                        }
                        $profileInsert->execute([$newId, $companyId, $values['designation'] !== '' ? $values['designation'] : null]);
                    }

                    admin_notify(
                        $db,
                        $newId,
                        'Welcome to the SVIET placement portal',
                        'An account was created for you by the Training & Placement Cell. Sign in with your college email and the temporary password you were given, then review your profile.',
                    );
                    $created[] = ['name' => $values['name'], 'email' => $values['email'], 'role' => $values['role'], 'password' => $password];
                }

                admin_audit($db, (int) $user['id'], 'accounts_imported', 'user', null, [
                    'created' => count($created),
                    'skipped' => count($skipped),
                    'file' => (string) ($staged['file'] ?? ''),
                ]);
                $db->commit();
                unset($_SESSION['admin_import']);
                $_SESSION['admin_import_credentials'] = $created;
                $_SESSION['admin_import_skipped'] = $skipped;
                $summary = count($created) . ' account' . (count($created) === 1 ? '' : 's') . ' created';
                if ($skipped !== []) {
                    $summary .= '; ' . count($skipped) . ' row' . (count($skipped) === 1 ? '' : 's') . ' skipped';
                }
                admin_flash_redirect('students', 'success', $summary . '. Download the credential sheet now — it is shown once.', ['imported' => '1']);
            } elseif ($action === 'offer_create') {
                if (!$offersReady) {
                    throw new RuntimeException('The offers table is missing. Run database/migrations/2026-10-20-admin-portal-offers.sql first.');
                }
                $applicationId = admin_id($_POST['application_id'] ?? null);
                $ctc = admin_post('ctc_lpa');
                $offerDate = admin_post('offer_date');
                $status = admin_post('status');
                if ($applicationId === 0 || !in_array($status, ['offered', 'accepted'], true)) {
                    throw new InvalidArgumentException('Choose a selected candidate and a valid offer state.');
                }
                if ($ctc !== '' && (filter_var($ctc, FILTER_VALIDATE_FLOAT) === false || (float) $ctc < 0 || (float) $ctc > 500)) {
                    throw new InvalidArgumentException('CTC must be between 0 and 500 LPA.');
                }
                $parsedDate = null;
                if ($offerDate !== '') {
                    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $offerDate);
                    if (!$parsedDate) {
                        throw new InvalidArgumentException('Enter a valid offer date.');
                    }
                }

                $db->beginTransaction();
                $statement = $db->prepare(
                    "SELECT a.id, a.status, a.student_id, u.name, j.title, c.name AS company_name FROM applications a "
                    . 'JOIN users u ON u.id = a.student_id JOIN jobs j ON j.id = a.job_id JOIN companies c ON c.id = j.company_id '
                    . "WHERE a.id = ? AND a.status = 'selected' FOR UPDATE",
                );
                $statement->execute([$applicationId]);
                $application = $statement->fetch(PDO::FETCH_ASSOC);
                if (!$application) {
                    throw new RuntimeException('Offers can only be recorded for applications marked as selected.');
                }
                $existing = $db->prepare('SELECT id FROM offers WHERE application_id = ? LIMIT 1');
                $existing->execute([$applicationId]);
                if ($existing->fetchColumn()) {
                    throw new RuntimeException('That application already has an offer recorded.');
                }
                $db->prepare('INSERT INTO offers (application_id, ctc_lpa, offer_date, status, created_by) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$applicationId, $ctc !== '' ? $ctc : null, $parsedDate?->format('Y-m-d'), $status, (int) $user['id']]);
                $offerId = (int) $db->lastInsertId();
                admin_notify(
                    $db,
                    (int) $application['student_id'],
                    'Offer recorded for ' . $application['title'],
                    'The Training & Placement Cell recorded an offer from ' . $application['company_name'] . '. Confirm whether you accept it in the portal.',
                );
                admin_audit($db, (int) $user['id'], 'offer_created', 'offer', $offerId, [
                    'application_id' => $applicationId,
                    'ctc_lpa' => $ctc !== '' ? $ctc : null,
                    'status' => $status,
                ]);
                $db->commit();
                $message = 'Offer recorded for ' . $application['name'] . '.';
            } elseif ($action === 'offer_update') {
                if (!$offersReady) {
                    throw new RuntimeException('The offers table is missing. Run database/migrations/2026-10-20-admin-portal-offers.sql first.');
                }
                $offerId = admin_id($_POST['id'] ?? null);
                $status = admin_post('status');
                $joinedOn = admin_post('joined_on');
                if ($offerId === 0 || !in_array($status, ['offered', 'accepted', 'declined', 'joined', 'revoked'], true)) {
                    throw new InvalidArgumentException('Choose a valid offer state.');
                }
                $parsedDate = null;
                if ($joinedOn !== '') {
                    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $joinedOn);
                    if (!$parsedDate) {
                        throw new InvalidArgumentException('Enter a valid joining date.');
                    }
                }
                $db->beginTransaction();
                $statement = $db->prepare(
                    'SELECT o.id, o.status, a.student_id, j.title FROM offers o JOIN applications a ON a.id = o.application_id JOIN jobs j ON j.id = a.job_id WHERE o.id = ? FOR UPDATE',
                );
                $statement->execute([$offerId]);
                $offer = $statement->fetch(PDO::FETCH_ASSOC);
                if (!$offer) {
                    throw new RuntimeException('Offer not found.');
                }
                $db->prepare('UPDATE offers SET status = ?, joined_on = ? WHERE id = ?')
                    ->execute([$status, $status === 'joined' ? ($parsedDate?->format('Y-m-d') ?? date('Y-m-d')) : null, $offerId]);
                admin_notify($db, (int) $offer['student_id'], 'Offer status updated', 'Your offer for ' . $offer['title'] . ' is now marked as ' . admin_status_label($status) . '.');
                admin_audit($db, (int) $user['id'], 'offer_' . $status, 'offer', $offerId, ['previous' => $offer['status'], 'new_status' => $status]);
                $db->commit();
                $message = 'Offer updated to ' . admin_status_label($status) . '.';
            } else {
                throw new InvalidArgumentException('Unknown action.');
            }
        } catch (Throwable $exception) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            $known = $exception instanceof InvalidArgumentException || $exception instanceof RuntimeException;
            if (!$known) {
                error_log('Admin action failure (' . $action . '): ' . $exception->getMessage());
            }
            admin_flash_redirect($returnSection, 'error', $known ? $exception->getMessage() : 'That action could not be completed. Check the logs and try again.');
        }

        if ($message !== '') {
            admin_flash_redirect($returnSection, 'success', $message);
        }
    }
}

/* ------------------------------------------------------------------ reports export */
if ($section === 'reports' && admin_get('export') !== '') {
    $export = admin_get('export');
    if (in_array($export, ['offers', 'branch'], true) && !$offersReady) {
        admin_flash_redirect('reports', 'error', 'Offer exports need the offers table. Run database/migrations/2026-10-20-admin-portal-offers.sql first.');
    }
    if (!in_array($export, ['students', 'offers', 'branch'], true)) {
        admin_flash_redirect('reports', 'error', 'Unknown export.');
    }
    try {
        if ($export === 'students') {
            $rows = $db->query(
                "SELECT u.name, u.email, u.status, COALESCE(sp.branch, '') , COALESCE(sp.cgpa, ''), COALESCE(sp.graduation_year, '') "
                . "FROM users u LEFT JOIN student_profiles sp ON sp.user_id = u.id WHERE u.role = 'student' ORDER BY u.name",
            )->fetchAll(PDO::FETCH_NUM);
            admin_export_csv('sviet-students-' . date('Y-m-d') . '.csv', ['name', 'email', 'account_status', 'branch', 'cgpa', 'graduation_year'], $rows);
        }
        if ($export === 'offers') {
            $rows = $db->query(
                'SELECT u.name, u.email, j.title, c.name, o.status, COALESCE(o.ctc_lpa, \'\'), COALESCE(o.offer_date, \'\'), COALESCE(o.joined_on, \'\') '
                . 'FROM offers o JOIN applications a ON a.id = o.application_id JOIN users u ON u.id = a.student_id '
                . 'JOIN jobs j ON j.id = a.job_id JOIN companies c ON c.id = j.company_id ORDER BY o.created_at DESC',
            )->fetchAll(PDO::FETCH_NUM);
            admin_export_csv('sviet-offers-' . date('Y-m-d') . '.csv', ['student', 'email', 'job_title', 'company', 'offer_status', 'ctc_lpa', 'offer_date', 'joined_on'], $rows);
        }
        if ($export === 'branch') {
            $rows = $db->query(
                "SELECT COALESCE(sp.branch, 'Unspecified'), COUNT(DISTINCT u.id), "
                . "COUNT(DISTINCT CASE WHEN o.status = 'joined' THEN u.id END), COUNT(DISTINCT a.id) "
                . 'FROM users u LEFT JOIN student_profiles sp ON sp.user_id = u.id '
                . 'LEFT JOIN applications a ON a.student_id = u.id LEFT JOIN offers o ON o.application_id = a.id '
                . "WHERE u.role = 'student' GROUP BY sp.branch ORDER BY 2 DESC",
            )->fetchAll(PDO::FETCH_NUM);
            admin_export_csv('sviet-branch-report-' . date('Y-m-d') . '.csv', ['branch', 'students', 'placed_students', 'applications'], $rows);
        }
    } catch (Throwable $exception) {
        error_log('Admin export failure: ' . $exception->getMessage());
        admin_flash_redirect('reports', 'error', 'That export could not be generated.');
    }
}

/* ------------------------------------------------------------------ shared data */
$stats = [];
$statQueries = [
    'Students' => "SELECT COUNT(*) FROM users WHERE role = 'student'",
    'Pending accounts' => "SELECT COUNT(*) FROM users WHERE status = 'pending' AND role IN ('student', 'recruiter', 'tpo')",
    'Pending companies' => "SELECT COUNT(*) FROM companies WHERE approval_status = 'pending'",
    'Published jobs' => "SELECT COUNT(*) FROM jobs WHERE status = 'published'",
    'Applications' => "SELECT COUNT(*) FROM applications WHERE status <> 'withdrawn'",
    'Selected' => "SELECT COUNT(DISTINCT student_id) FROM applications WHERE status = 'selected'",
];
foreach ($statQueries as $label => $sql) {
    try {
        $stats[$label] = (int) $db->query($sql)->fetchColumn();
    } catch (Throwable $exception) {
        $stats[$label] = null;
    }
}
$offersSummary = ['offered' => 0, 'accepted' => 0, 'declined' => 0, 'joined' => 0, 'revoked' => 0, 'median_ctc' => null, 'highest_ctc' => null];
if ($offersReady) {
    try {
        foreach ($db->query('SELECT status, COUNT(*) AS total FROM offers GROUP BY status')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $offersSummary[$row['status']] = (int) $row['total'];
        }
        $ctcs = $db->query("SELECT ctc_lpa FROM offers WHERE ctc_lpa IS NOT NULL AND status IN ('accepted', 'joined') ORDER BY ctc_lpa")->fetchAll(PDO::FETCH_COLUMN);
        if ($ctcs !== []) {
            $medianIndex = intdiv(count($ctcs), 2);
            $offersSummary['median_ctc'] = count($ctcs) % 2 === 0
                ? (((float) $ctcs[$medianIndex - 1] + (float) $ctcs[$medianIndex]) / 2)
                : (float) $ctcs[$medianIndex];
            $offersSummary['highest_ctc'] = (float) max(array_map('floatval', $ctcs));
        }
    } catch (Throwable $exception) {
        error_log('Admin offer summary failure: ' . $exception->getMessage());
    }
}
$stats['Offers accepted'] = $offersSummary['accepted'] + $offersSummary['joined'];
$stats['Joined'] = $offersSummary['joined'];
$pendingApprovals = ($stats['Pending accounts'] ?? 0) + ($stats['Pending companies'] ?? 0);

/* ------------------------------------------------------------------ section data */
$activity = [];
$approvalUsers = [];
$approvalCompanies = [];
$students = [];
$dataHealth = ['incomplete' => 0, 'no_branch' => 0, 'no_cgpa' => 0, 'no_year' => 0];
$pendingOffers = [];
$offers = [];
$offerStatusFilter = admin_get('offer_status');
if (!in_array($offerStatusFilter, ['', 'offered', 'accepted', 'declined', 'joined', 'revoked'], true)) {
    $offerStatusFilter = '';
}
$jobs = [];
$jobStatusFilter = admin_get('job_status');
if (!in_array($jobStatusFilter, ['', 'draft', 'published', 'paused', 'closed'], true)) {
    $jobStatusFilter = '';
}
$branchReport = [];
$companyReport = [];
$reportYear = admin_get('year');
if ($reportYear !== '' && !preg_match('/^(20)[0-9]{2}$/', $reportYear)) {
    $reportYear = '';
}
$graduationYears = [];
$announcements = [];
$auditEntries = [];
$auditAction = admin_get('audit_action');
$auditAction = mb_substr(preg_replace('/[^a-z_]/', '', strtolower($auditAction)) ?? '', 0, 40);
$importPreview = null;
$importTemplate = '';
$importCredentials = [];
$importSkipped = [];
$recentActivity = [];
$sectionError = '';

try {
    if ($section === 'overview') {
        $recentActivity = $db->query(
            'SELECT al.action, al.entity_type, al.entity_id, al.created_at, al.metadata_json, u.name AS actor_name '
            . 'FROM audit_logs al LEFT JOIN users u ON u.id = al.actor_id ORDER BY al.created_at DESC LIMIT 12',
        )->fetchAll(PDO::FETCH_ASSOC);
        $approvalUsers = $db->query(
            "SELECT id, name, email, role, status, created_at FROM users WHERE status = 'pending' AND role IN ('recruiter', 'student', 'tpo') ORDER BY created_at ASC LIMIT 5",
        )->fetchAll(PDO::FETCH_ASSOC);
        $approvalCompanies = $db->query(
            "SELECT c.id, c.name, c.industry, c.created_at, COUNT(rp.user_id) AS recruiter_count FROM companies c "
            . "LEFT JOIN recruiter_profiles rp ON rp.company_id = c.id WHERE c.approval_status = 'pending' "
            . 'GROUP BY c.id ORDER BY c.created_at ASC LIMIT 5',
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    if ($section === 'approvals') {
        $approvalUsers = $db->query(
            "SELECT id, name, email, role, status, created_at FROM users WHERE status IN ('pending', 'suspended') AND role IN ('recruiter', 'student', 'tpo') ORDER BY created_at ASC LIMIT 200",
        )->fetchAll(PDO::FETCH_ASSOC);
        $approvalCompanies = $db->query(
            "SELECT c.id, c.name, c.website, c.industry, c.approval_status, c.created_at, COUNT(rp.user_id) AS recruiter_count "
            . 'FROM companies c LEFT JOIN recruiter_profiles rp ON rp.company_id = c.id '
            . "WHERE c.approval_status IN ('pending', 'rejected') GROUP BY c.id ORDER BY c.created_at ASC LIMIT 200",
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    if ($section === 'students') {
        $importTemplate = $importService->template();
        $students = $db->query(
            'SELECT u.id, u.name, u.email, u.status, u.created_at, sp.branch, sp.cgpa, sp.graduation_year, '
            . 'COUNT(DISTINCT a.id) AS applications, COUNT(DISTINCT CASE WHEN a.status = \'selected\' THEN a.id END) AS selections, '
            . ($offersReady ? 'COUNT(DISTINCT o.id) AS offers ' : '0 AS offers ')
            . 'FROM users u LEFT JOIN student_profiles sp ON sp.user_id = u.id '
            . 'LEFT JOIN applications a ON a.student_id = u.id ' . ($offersReady ? 'LEFT JOIN offers o ON o.application_id = a.id ' : '')
            . "WHERE u.role = 'student' GROUP BY u.id, sp.branch, sp.cgpa, sp.graduation_year ORDER BY u.created_at DESC LIMIT 200",
        )->fetchAll(PDO::FETCH_ASSOC);
        $healthRow = $db->query(
            "SELECT COUNT(*) AS total, "
            . 'SUM(sp.user_id IS NULL) AS missing_profile, SUM(sp.branch IS NULL OR sp.branch = \'\') AS no_branch, '
            . 'SUM(sp.cgpa IS NULL) AS no_cgpa, SUM(sp.graduation_year IS NULL) AS no_year '
            . "FROM users u LEFT JOIN student_profiles sp ON sp.user_id = u.id WHERE u.role = 'student'",
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $dataHealth = [
            'incomplete' => (int) ($healthRow['missing_profile'] ?? 0) + (int) ($healthRow['no_branch'] ?? 0) + (int) ($healthRow['no_cgpa'] ?? 0) + (int) ($healthRow['no_year'] ?? 0),
            'no_branch' => (int) ($healthRow['no_branch'] ?? 0),
            'no_cgpa' => (int) ($healthRow['no_cgpa'] ?? 0),
            'no_year' => (int) ($healthRow['no_year'] ?? 0),
        ];
        if (isset($_SESSION['admin_import'])) {
            $importPreview = $_SESSION['admin_import'];
            if (time() - (int) ($importPreview['created_at'] ?? 0) > 1800) {
                unset($_SESSION['admin_import']);
                $importPreview = null;
            }
        }
        if (isset($_SESSION['admin_import_credentials'])) {
            $importCredentials = (array) $_SESSION['admin_import_credentials'];
            unset($_SESSION['admin_import_credentials']);
        }
        if (isset($_SESSION['admin_import_skipped'])) {
            $importSkipped = (array) $_SESSION['admin_import_skipped'];
            unset($_SESSION['admin_import_skipped']);
        }
    }

    if ($section === 'placements' && $offersReady) {
        $pendingOffers = $db->query(
            "SELECT a.id, a.updated_at, u.name, u.email, j.title, c.name AS company_name FROM applications a "
            . 'JOIN users u ON u.id = a.student_id JOIN jobs j ON j.id = a.job_id JOIN companies c ON c.id = j.company_id '
            . "WHERE a.status = 'selected' AND NOT EXISTS (SELECT 1 FROM offers o WHERE o.application_id = a.id) "
            . 'ORDER BY a.updated_at DESC LIMIT 100',
        )->fetchAll(PDO::FETCH_ASSOC);
        $offerSql = 'SELECT o.id, o.ctc_lpa, o.offer_date, o.status, o.joined_on, o.created_at, u.name, u.email, j.title, c.name AS company_name '
            . 'FROM offers o JOIN applications a ON a.id = o.application_id JOIN users u ON u.id = a.student_id '
            . 'JOIN jobs j ON j.id = a.job_id JOIN companies c ON c.id = j.company_id';
        if ($offerStatusFilter !== '') {
            $offerStatement = $db->prepare($offerSql . ' WHERE o.status = ? ORDER BY o.updated_at DESC LIMIT 200');
            $offerStatement->execute([$offerStatusFilter]);
            $offers = $offerStatement->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $offers = $db->query($offerSql . ' ORDER BY o.updated_at DESC LIMIT 200')->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    if ($section === 'jobs') {
        if ($jobStatusFilter !== '') {
            $jobStatement = $db->prepare(
                'SELECT j.id, j.title, j.status, j.closes_at, j.updated_at, c.name AS company_name, c.approval_status, '
                . '(SELECT COUNT(*) FROM applications a WHERE a.job_id = j.id) AS applications '
                . 'FROM jobs j JOIN companies c ON c.id = j.company_id WHERE j.status = ? ORDER BY j.updated_at DESC LIMIT 200',
            );
            $jobStatement->execute([$jobStatusFilter]);
            $jobs = $jobStatement->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $jobs = $db->query(
                'SELECT j.id, j.title, j.status, j.closes_at, j.updated_at, c.name AS company_name, c.approval_status, '
                . '(SELECT COUNT(*) FROM applications a WHERE a.job_id = j.id) AS applications '
                . 'FROM jobs j JOIN companies c ON c.id = j.company_id ORDER BY j.updated_at DESC LIMIT 200',
            )->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    if ($section === 'reports') {
        $graduationYears = $db->query(
            "SELECT DISTINCT sp.graduation_year FROM student_profiles sp WHERE sp.graduation_year IS NOT NULL ORDER BY sp.graduation_year DESC LIMIT 12",
        )->fetchAll(PDO::FETCH_COLUMN);
        $yearClause = $reportYear !== '' ? ' AND sp.graduation_year = ' . (int) $reportYear : '';
        $branchReport = $db->query(
            "SELECT COALESCE(sp.branch, 'Unspecified') AS branch, COUNT(DISTINCT u.id) AS students, "
            . "COUNT(DISTINCT CASE WHEN o.status = 'joined' THEN u.id END) AS placed, "
            . 'COUNT(DISTINCT CASE WHEN o.id IS NOT NULL THEN u.id END) AS offered_students, '
            . 'COUNT(DISTINCT a.id) AS applications, COALESCE(AVG(CASE WHEN o.status IN (\'accepted\', \'joined\') THEN o.ctc_lpa END), 0) AS avg_ctc '
            . 'FROM users u LEFT JOIN student_profiles sp ON sp.user_id = u.id LEFT JOIN applications a ON a.student_id = u.id '
            . 'LEFT JOIN offers o ON o.application_id = a.id '
            . "WHERE u.role = 'student'" . $yearClause . ' GROUP BY sp.branch ORDER BY students DESC',
        )->fetchAll(PDO::FETCH_ASSOC);
        $companyReport = $db->query(
            'SELECT c.name AS company_name, COUNT(DISTINCT a.id) AS applications, '
            . 'COUNT(DISTINCT CASE WHEN a.status = \'selected\' THEN a.id END) AS selected, '
            . "COUNT(DISTINCT CASE WHEN o.status IN ('accepted', 'joined') THEN o.id END) AS accepted, "
            . "COUNT(DISTINCT CASE WHEN o.status = 'joined' THEN o.id END) AS joined, "
            . 'COALESCE(MAX(o.ctc_lpa), 0) AS highest_ctc '
            . 'FROM companies c JOIN jobs j ON j.company_id = c.id LEFT JOIN applications a ON a.job_id = j.id '
            . 'LEFT JOIN offers o ON o.application_id = a.id GROUP BY c.id ORDER BY applications DESC LIMIT 50',
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    if ($section === 'comms') {
        $announcements = $db->query(
            'SELECT an.id, an.title, an.body, an.audience, an.published_at, an.created_at, u.name AS author, '
            . '(SELECT COUNT(*) FROM notifications n WHERE n.title = an.title) AS delivered '
            . 'FROM announcements an JOIN users u ON u.id = an.author_id ORDER BY an.published_at DESC, an.id DESC LIMIT 50',
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    if ($section === 'governance') {
        if ($auditAction !== '') {
            $auditStatement = $db->prepare(
                'SELECT al.action, al.entity_type, al.entity_id, al.created_at, al.metadata_json, u.name AS actor_name '
                . 'FROM audit_logs al LEFT JOIN users u ON u.id = al.actor_id WHERE al.action LIKE ? '
                . 'ORDER BY al.created_at DESC LIMIT 150',
            );
            $auditStatement->execute(['%' . $auditAction . '%']);
            $auditEntries = $auditStatement->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $auditEntries = $db->query(
                'SELECT al.action, al.entity_type, al.entity_id, al.created_at, al.metadata_json, u.name AS actor_name '
                . 'FROM audit_logs al LEFT JOIN users u ON u.id = al.actor_id ORDER BY al.created_at DESC LIMIT 150',
            )->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (Throwable $exception) {
    error_log('Admin section query failure (' . $section . '): ' . $exception->getMessage());
    $sectionError = 'Some of this section could not be loaded. Check the schema and logs, then refresh.';
}

$sectionTitles = [
    'overview' => ['Control centre', 'Approvals, placements and governance for the SVIET Training & Placement Cell.'],
    'approvals' => ['Approvals', 'Review accounts and companies, and send a reason with every decision.'],
    'students' => ['Students', 'Import a batch, watch profile completeness, and open placement histories.'],
    'placements' => ['Placements', 'Record offers, track acceptance and joining, and keep package data honest.'],
    'jobs' => ['Jobs', 'Moderate published roles and stop anything that should not be live.'],
    'reports' => ['Reports', 'Branch and company funnels with exportable numbers for accreditation.'],
    'comms' => ['Announcements', 'Publish targeted notices and see how many accounts were reached.'],
    'governance' => ['Governance', 'Every state change is audited. Search the trail here.'],
];
[$pageTitle, $pageDescription] = $sectionTitles[$section];

$appCssVersion = asset_version();
$adminCssVersion = asset_version();
$adminNavJsVersion = asset_version();

require __DIR__ . '/views/admin/layout-start.php';
require __DIR__ . '/views/admin/' . $section . '.php';
require __DIR__ . '/views/admin/layout-end.php';
