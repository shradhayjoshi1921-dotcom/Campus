<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\Security;

require dirname(__DIR__) . '/bootstrap/app.php';

// Resolve the requested route and keep registration roles within public options.
$page =
    isset($_GET['page']) && is_string($_GET['page']) ? $_GET['page'] : 'home';
$selectedRole =
    isset($_GET['role']) && is_string($_GET['role'])
        ? $_GET['role']
        : 'student';
if (!in_array($selectedRole, ['student', 'recruiter'], true)) {
    $selectedRole = 'student';
}
$user = Security::currentUser();
$error = '';
$success = '';

// The career studio pages are standalone scripts with their own student guard.
// Keep old or bookmarked index.php links working instead of returning a 404.
$studioRedirects = [
    'career' => 'career.php',
    'resume-review' => 'resume-review.php',
    'practice' => 'practice.php',
];
if (isset($studioRedirects[$page]) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    header('Location: ' . $studioRedirects[$page], true, 302);
    exit;
}

// Handle state-changing requests before route data is loaded.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
        http_response_code(419);
        $error = 'Your session token expired. Refresh and try again.';
    } elseif ($action === 'register') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $role = $_POST['role'] ?? 'student';
        $selectedRole = in_array($role, ['student', 'recruiter'], true)
            ? $role
            : 'student';
        if (
            $name === '' ||
            !filter_var($email, FILTER_VALIDATE_EMAIL) ||
            strlen($password) < 10 ||
            !in_array($role, ['student', 'recruiter'], true)
        ) {
            $error =
                'Enter a name, valid email, password of at least 10 characters, and a supported role.';
        } else {
            try {
                $db = Database::connection();
                $db->beginTransaction();
                $stmt = $db->prepare(
                    'INSERT INTO users (name,email,password_hash,role,status) VALUES (?,?,?,?,?)',
                );
                $stmt->execute([
                    $name,
                    $email,
                    password_hash($password, PASSWORD_DEFAULT),
                    $role,
                    $role === 'recruiter' ? 'pending' : 'active',
                ]);
                $id = (int) $db->lastInsertId();
                if ($role === 'student') {
                    $db->prepare(
                        'INSERT INTO student_profiles (user_id) VALUES (?)',
                    )->execute([$id]);
                }
                $db->commit();
                $success =
                    $role === 'recruiter'
                        ? 'Your recruiter account was created and is awaiting Training & Placement Cell approval. You can sign in after approval.'
                        : 'Your account is ready. You can now sign in.';
                $page = 'login';
            } catch (Throwable $e) {
                if (isset($db) && $db->inTransaction()) {
                    $db->rollBack();
                }
                if ($e instanceof PDOException && $e->getCode() === '23000') {
                    $error = 'An account with that email already exists.';
                } else {
                    error_log(
                        'Portal registration failure: ' . $e->getMessage(),
                    );
                    $error =
                        'Registration is temporarily unavailable. Please try again later.';
                }
            }
        }
    } elseif ($action === 'login') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        try {
            $stmt = Database::connection()->prepare(
                'SELECT id,name,email,password_hash,role,status FROM users WHERE email=? LIMIT 1',
            );
            $stmt->execute([$email]);
            $row = $stmt->fetch();
            if (
                $row &&
                $row['status'] === 'active' &&
                password_verify($password, $row['password_hash'])
            ) {
                session_regenerate_id(true);
                unset($row['password_hash']);
                $_SESSION['user'] = $row;
                // One sign-in for every role: route people to their own workspace.
                $landing = match ((string) $row['role']) {
                    'recruiter' => 'recruiter.php',
                    'tpo', 'admin' => 'admin.php',
                    default => 'index.php?page=dashboard',
                };
                header('Location: ' . $landing, true, 303);
                exit();
            }
            $error =
                'We could not sign you in. Check your details or, if you registered as a recruiter, wait for approval.';
        } catch (Throwable $e) {
            error_log('Portal sign-in failure: ' . $e->getMessage());
            $error =
                'Sign-in is temporarily unavailable. Please try again later.';
        }
    } elseif ($action === 'upload_resume') {
        Security::requireRole(['student']);
        $file = $_FILES['resume'] ?? null;
        $maxBytes = 5 * 1024 * 1024;
        $failure = '';
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $failure = 'Choose a PDF file to upload.';
        } elseif ((int) ($file['size'] ?? 0) > $maxBytes) {
            $failure = 'Resume PDFs must be 5 MB or smaller.';
        } else {
            $tmp = (string) ($file['tmp_name'] ?? '');
            $original = trim(basename((string) ($file['name'] ?? 'resume.pdf')));
            $handle = is_file($tmp) ? fopen($tmp, 'rb') : false;
            $magic = $handle ? (string) fread($handle, 5) : '';
            if ($handle) {
                fclose($handle);
            }
            $mime = '';
            if (is_file($tmp)) {
                if (class_exists('finfo')) {
                    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
                } elseif (function_exists('mime_content_type')) {
                    $mime = (string) mime_content_type($tmp);
                }
            }
            if ($magic !== '%PDF-') {
                $failure = 'That file is not a PDF. Export your resume as PDF and try again.';
            } elseif (!preg_match('/\.pdf$/i', $original)) {
                $failure = 'The file must end in .pdf.';
            } elseif ($mime !== '' && $mime !== 'application/pdf') {
                $failure = 'The uploaded file does not look like a PDF document.';
            } else {
                $directory = dirname(__DIR__) . '/storage/uploads/resumes';
                if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                    $failure = 'Resume storage is unavailable right now. Ask the placement cell to check server permissions.';
                } else {
                    $stored = bin2hex(random_bytes(16)) . '.pdf';
                    $target = $directory . '/' . $stored;
                    if (!move_uploaded_file($tmp, $target)) {
                        $failure = 'The upload could not be saved. Please try again.';
                    } else {
                        try {
                            $db = Database::connection();
                            $db->beginTransaction();
                            $old = $db->prepare('SELECT id, stored_name FROM resumes WHERE student_id = ?');
                            $old->execute([(int) $user['id']]);
                            $previous = $old->fetchAll();
                            $db->prepare(
                                'INSERT INTO resumes (student_id, original_name, stored_name, mime_type, file_size) VALUES (?, ?, ?, ?, ?)',
                            )->execute([
                                (int) $user['id'],
                                mb_substr($original !== '' ? $original : 'resume.pdf', 0, 255),
                                $stored,
                                'application/pdf',
                                (int) filesize($target),
                            ]);
                            $db->prepare('DELETE FROM resumes WHERE student_id = ? AND stored_name <> ?')
                                ->execute([(int) $user['id'], $stored]);
                            $db->commit();
                            foreach ($previous as $row) {
                                $path = $directory . '/' . basename((string) $row['stored_name']);
                                if (is_file($path)) {
                                    @unlink($path);
                                }
                            }
                            $success = 'Resume uploaded. Recruiters reviewing your application can download this PDF.';
                        } catch (Throwable $exception) {
                            if (isset($db) && $db->inTransaction()) {
                                $db->rollBack();
                            }
                            @unlink($target);
                            error_log('Resume upload failure: ' . $exception->getMessage());
                            $failure = 'The resume could not be saved. Please try again.';
                        }
                    }
                }
            }
        }
        if ($failure !== '') {
            $error = $failure;
        }
    } elseif ($action === 'delete_resume') {
        Security::requireRole(['student']);
        try {
            $db = Database::connection();
            $statement = $db->prepare('SELECT stored_name FROM resumes WHERE student_id = ?');
            $statement->execute([(int) $user['id']]);
            $files = $statement->fetchAll(PDO::FETCH_COLUMN);
            $db->prepare('DELETE FROM resumes WHERE student_id = ?')->execute([(int) $user['id']]);
            $directory = dirname(__DIR__) . '/storage/uploads/resumes';
            foreach ($files as $name) {
                $path = $directory . '/' . basename((string) $name);
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            $success = 'Resume removed from your profile.';
        } catch (Throwable $exception) {
            error_log('Resume removal failure: ' . $exception->getMessage());
            $error = 'The resume could not be removed right now.';
        }
    } elseif ($action === 'mark_notifications_read') {
        try {
            Database::connection()
                ->prepare('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL')
                ->execute([(int) $user['id']]);
            $success = 'All notifications marked as read.';
        } catch (Throwable $exception) {
            error_log('Notification read failure: ' . $exception->getMessage());
            $error = 'Notifications could not be updated right now.';
        }
    } elseif ($action === 'save_profile') {
        Security::requireRole(['student']);
        $name = trim((string) ($_POST['name'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $branch = trim((string) ($_POST['branch'] ?? ''));
        $degree = trim((string) ($_POST['degree'] ?? ''));
        $semester = trim((string) ($_POST['semester'] ?? ''));
        $cgpa = trim((string) ($_POST['cgpa'] ?? ''));
        $year = trim((string) ($_POST['graduation_year'] ?? ''));
        $bio = trim((string) ($_POST['bio'] ?? ''));
        if (
            $name === '' ||
            mb_strlen($name) > 120 ||
            mb_strlen($phone) > 30 ||
            mb_strlen($branch) > 120 ||
            mb_strlen($degree) > 120 ||
            mb_strlen($bio) > 3000 ||
            ($semester !== '' &&
                (!ctype_digit($semester) ||
                    (int) $semester < 1 ||
                    (int) $semester > 20)) ||
            ($cgpa !== '' &&
                (!is_numeric($cgpa) ||
                    (float) $cgpa < 0 ||
                    (float) $cgpa > 10)) ||
            ($year !== '' &&
                (!ctype_digit($year) ||
                    (int) $year < 2000 ||
                    (int) $year > 2150))
        ) {
            $error =
                'Please check your profile fields. CGPA must be between 0 and 10, semester between 1 and 20, and graduation year must be valid.';
        } else {
            try {
                $db = Database::connection();
                $db->beginTransaction();
                $db->prepare(
                    "UPDATE users SET name=? WHERE id=? AND role='student'",
                )->execute([$name, (int) $user['id']]);
                $stmt = $db->prepare(
                    'INSERT INTO student_profiles (user_id,phone,branch,degree,semester,cgpa,graduation_year,bio) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE phone=VALUES(phone),branch=VALUES(branch),degree=VALUES(degree),semester=VALUES(semester),cgpa=VALUES(cgpa),graduation_year=VALUES(graduation_year),bio=VALUES(bio)',
                );
                $stmt->execute([
                    (int) $user['id'],
                    $phone ?: null,
                    $branch ?: null,
                    $degree ?: null,
                    $semester !== '' ? (int) $semester : null,
                    $cgpa !== '' ? (float) $cgpa : null,
                    $year !== '' ? (int) $year : null,
                    $bio ?: null,
                ]);
                $db->commit();
                $_SESSION['user']['name'] = $name;
                $user = $_SESSION['user'];
                $success = 'Your student profile has been saved.';
                $page = 'profile';
            } catch (Throwable $e) {
                if (isset($db) && $db->inTransaction()) {
                    $db->rollBack();
                }
                $error =
                    "We couldn't save your profile. Please try again shortly.";
                $page = 'profile';
            }
        }
    } elseif ($action === 'save_skills') {
        Security::requireRole(['student']);
        $raw = trim((string) ($_POST['skills'] ?? ''));
        if (mb_strlen($raw) > 2000) {
            $error = 'Please keep the skills list under 2,000 characters.';
            $page = 'profile';
        } else {
            try {
                $db = Database::connection();
                $names = array_values(
                    array_unique(
                        array_filter(
                            array_map(
                                fn($v) => mb_substr(trim($v), 0, 100),
                                explode(',', $raw),
                            ),
                            fn($v) => $v !== '',
                        ),
                    ),
                );
                $db->beginTransaction();
                $db->prepare(
                    'DELETE FROM student_skills WHERE student_id=?',
                )->execute([(int) $user['id']]);
                $find = $db->prepare('SELECT id FROM skills WHERE name=?');
                $insert = $db->prepare('INSERT INTO skills (name) VALUES (?)');
                $link = $db->prepare(
                    'INSERT INTO student_skills (student_id,skill_id,proficiency) VALUES (?,?,?)',
                );
                foreach ($names as $name) {
                    $find->execute([$name]);
                    $id = $find->fetchColumn();
                    if (!$id) {
                        try {
                            $insert->execute([$name]);
                            $id = $db->lastInsertId();
                        } catch (PDOException $ex) {
                            $find->execute([$name]);
                            $id = $find->fetchColumn();
                        }
                    }
                    if ($id) {
                        $link->execute([
                            (int) $user['id'],
                            (int) $id,
                            'beginner',
                        ]);
                    }
                }
                $db->commit();
                $success = 'Your skills have been updated.';
                $page = 'profile';
            } catch (Throwable $e) {
                if (isset($db) && $db->inTransaction()) {
                    $db->rollBack();
                }
                $error =
                    "We couldn't save your skills. Please try again shortly.";
                $page = 'profile';
            }
        }
    } elseif ($action === 'apply_job') {
        Security::requireRole(['student']);
        $jobId = filter_var($_POST['job_id'] ?? null, FILTER_VALIDATE_INT);
        $note = trim((string) ($_POST['cover_note'] ?? ''));
        if (!$jobId || mb_strlen($note) > 2000) {
            $error =
                'Please select a valid job and keep your note under 2,000 characters.';
            $page = 'jobs';
        } else {
            try {
                $db = Database::connection();
                $db->beginTransaction();
                $q = $db->prepare(
                    "SELECT j.id,j.min_cgpa,j.eligible_branch,j.graduation_year,p.cgpa,p.branch,p.graduation_year AS student_year FROM jobs j JOIN companies c ON c.id=j.company_id LEFT JOIN student_profiles p ON p.user_id=? WHERE j.id=? AND j.status='published' AND c.approval_status='approved' AND (j.closes_at IS NULL OR j.closes_at>=NOW()) FOR UPDATE",
                );
                $q->execute([(int) $user['id'], (int) $jobId]);
                $job = $q->fetch();
                if (!$job) {
                    $db->rollBack();
                    $error = 'This job is unavailable or closed.';
                    $page = 'jobs';
                } elseif (
                    $job['cgpa'] === null ||
                    (float) $job['cgpa'] < (float) $job['min_cgpa'] ||
                    ($job['eligible_branch'] &&
                        strcasecmp(
                            (string) $job['eligible_branch'],
                            (string) $job['branch'],
                        ) !== 0) ||
                    ($job['graduation_year'] &&
                        (int) $job['graduation_year'] !==
                            (int) $job['student_year'])
                ) {
                    $db->rollBack();
                    $error =
                        'Your profile does not currently meet the mandatory academic requirements. Complete your profile and review the job criteria.';
                    $page = 'jobs';
                } else {
                    $q = $db->prepare(
                        'INSERT INTO applications(job_id,student_id,cover_note) VALUES(?,?,?)',
                    );
                    $q->execute([
                        (int) $jobId,
                        (int) $user['id'],
                        $note ?: null,
                    ]);
                    $appId = (int) $db->lastInsertId();
                    $db->prepare(
                        "INSERT INTO application_status_history(application_id,changed_by,old_status,new_status,note) VALUES(?,?,NULL,'submitted','Application submitted by student')",
                    )->execute([$appId, (int) $user['id']]);
                    $db->commit();
                    $success = 'Application submitted successfully.';
                    $page = 'applications';
                }
            } catch (Throwable $e) {
                if (isset($db) && $db->inTransaction()) {
                    $db->rollBack();
                }
                $error =
                    $e instanceof PDOException && $e->getCode() === '23000'
                        ? 'You have already applied for this job.'
                        : 'Application could not be submitted. Please try again.';
                $page = 'jobs';
            }
        }
    } elseif ($action === 'logout') {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'] ?? '',
                (bool) $params['secure'],
                (bool) $params['httponly'],
            );
        }
        session_destroy();
        header('Location: index.php');
        exit();
    }
}
// Resume download: the owner (or the placement cell) only, streamed from storage.
if ($page === 'resume' && $user) {
    $resumeId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    $canReview = in_array($user['role'], ['tpo', 'admin'], true);
    // A recruiter may read a resume only when that student has applied to one
    // of their own company's jobs. Anything else is a 404, not a 403 leak.
    $isRecruiter = $user['role'] === 'recruiter';
    try {
        $sql = match (true) {
            $canReview => 'SELECT r.*, u.name AS owner_name FROM resumes r JOIN users u ON u.id = r.student_id WHERE r.id = ? LIMIT 1',
            $isRecruiter => 'SELECT r.*, u.name AS owner_name FROM resumes r JOIN users u ON u.id = r.student_id '
                . 'WHERE r.id = ? AND EXISTS (SELECT 1 FROM applications a JOIN jobs j ON j.id = a.job_id '
                . 'JOIN recruiter_profiles rp ON rp.company_id = j.company_id '
                . 'WHERE a.student_id = r.student_id AND rp.user_id = ?) LIMIT 1',
            default => 'SELECT r.*, u.name AS owner_name FROM resumes r JOIN users u ON u.id = r.student_id WHERE r.id = ? AND r.student_id = ? LIMIT 1',
        };
        $statement = Database::connection()->prepare($sql);
        $statement->execute($canReview ? [$resumeId] : [$resumeId, (int) $user['id']]);
        $resume = $statement->fetch();
    } catch (Throwable $exception) {
        error_log('Resume lookup failure: ' . $exception->getMessage());
        $resume = false;
    }
    if ($resume && ($isRecruiter || $canReview)) {
        try {
            Database::connection()->prepare(
                'INSERT INTO audit_logs (actor_id, action, entity_type, entity_id, ip_hash, metadata_json) VALUES (?, ?, ?, ?, ?, ?)',
            )->execute([
                (int) $user['id'],
                'resume.viewed',
                'resume',
                $resumeId,
                isset($_SERVER['REMOTE_ADDR']) ? hash('sha256', (string) $_SERVER['REMOTE_ADDR']) : null,
                json_encode(['viewer_role' => (string) $user['role']], JSON_UNESCAPED_SLASHES),
            ]);
        } catch (Throwable $exception) {
            // An audit table problem must never block a legitimate download.
            error_log('Resume audit failure: ' . $exception->getMessage());
        }
    }
    $file = $resume
        ? dirname(__DIR__) . '/storage/uploads/resumes/' . basename((string) $resume['stored_name'])
        : '';
    if (!$resume || !is_file($file)) {
        http_response_code(404);
        $page = 'forbidden';
        $error = 'That resume is not available.';
    } else {
        $download = isset($_GET['download']) && $_GET['download'] === '1';
        $safeName = preg_replace('/[^A-Za-z0-9 ._-]/', '', (string) $resume['owner_name']) ?: 'student';
        header('Content-Type: application/pdf');
        header('Content-Length: ' . (string) filesize($file));
        header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $safeName . '-resume.pdf"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($file);
        exit();
    }
}

// Enforce role access before running route-specific queries.
if ($page === 'logout' && $user) {
    $page = 'dashboard';
}
if (in_array($page, ['dashboard', 'profile'], true) && !$user) {
    header('Location: index.php?page=login');
    exit();
}
if ($page === 'profile' && $user && $user['role'] !== 'student') {
    http_response_code(403);
    $page = 'forbidden';
}
if ($page === 'profile' && $user && $user['role'] === 'student') {
    try {
        $db = Database::connection();
        $q = $db->prepare(
            'SELECT u.name,u.email,p.* FROM users u LEFT JOIN student_profiles p ON p.user_id=u.id WHERE u.id=? AND u.role=\'student\'',
        );
        $q->execute([(int) $user['id']]);
        $profile = $q->fetch() ?: [];
        $q = $db->prepare(
            'SELECT s.name FROM student_skills ss JOIN skills s ON s.id=ss.skill_id WHERE ss.student_id=? ORDER BY s.name',
        );
        $q->execute([(int) $user['id']]);
        $profileSkills = array_column($q->fetchAll(), 'name');
    } catch (Throwable $e) {
        $profileError = true;
        $profile = [];
        $profileSkills = [];
    }
}
if (in_array($page, ['jobs', 'applications', 'notifications'], true) && !$user) {
    header('Location: index.php?page=login');
    exit();
}
if (
    in_array($page, ['jobs', 'applications', 'notifications'], true) &&
    $user &&
    $user['role'] !== 'student'
) {
    http_response_code(403);
    $page = 'forbidden';
}
$jobSkillMap = [];
if ($page === 'jobs' && $user && $user['role'] === 'student') {
    try {
        $db = Database::connection();
        $q = $db->query(
            "SELECT j.*,c.name AS company_name FROM jobs j JOIN companies c ON c.id=j.company_id WHERE j.status='published' AND c.approval_status='approved' AND (j.closes_at IS NULL OR j.closes_at>=NOW()) ORDER BY j.created_at DESC",
        );
        $jobs = $q->fetchAll();
        if ($jobs) {
            $jobIds = array_map(static fn (array $job): int => (int) $job['id'], $jobs);
            $placeholders = implode(',', array_fill(0, count($jobIds), '?'));
            $skillQuery = $db->prepare(
                'SELECT js.job_id, js.requirement_type, s.name FROM job_skills js JOIN skills s ON s.id = js.skill_id '
                . 'WHERE js.job_id IN (' . $placeholders . ') ORDER BY s.name',
            );
            $skillQuery->execute($jobIds);
            foreach ($skillQuery->fetchAll(PDO::FETCH_ASSOC) as $skillRow) {
                $jobSkillMap[(int) $skillRow['job_id']][$skillRow['requirement_type'] === 'preferred' ? 'preferred' : 'required'][] = (string) $skillRow['name'];
            }
        }
        $q = $db->prepare('SELECT job_id FROM applications WHERE student_id=?');
        $q->execute([(int) $user['id']]);
        $appliedJobIds = array_map(
            'intval',
            array_column($q->fetchAll(), 'job_id'),
        );
    } catch (Throwable $e) {
        $jobsError = true;
        $jobs = [];
        $appliedJobIds = [];
    }
}
if ($page === 'applications' && $user && $user['role'] === 'student') {
    try {
        $q = Database::connection()->prepare(
            'SELECT a.id,a.status,a.applied_at,j.title,j.location,j.employment_type,c.name AS company_name FROM applications a JOIN jobs j ON j.id=a.job_id JOIN companies c ON c.id=j.company_id WHERE a.student_id=? ORDER BY a.applied_at DESC',
        );
        $q->execute([(int) $user['id']]);
        $applications = $q->fetchAll();
    } catch (Throwable $e) {
        $applicationsError = true;
        $applications = [];
    }
}
// Shared student workspace data: resume, profile strength, unread notices.
$resume = null;
$profileStrength = null;
$profileStrengthParts = [];
$applicationTimeline = [];
$notifications = [];
$unreadNotifications = 0;
$upcomingInterviews = [];
$studentOffers = [];
$isStudentWorkspace = $user && $user['role'] === 'student'
    && in_array($page, ['dashboard', 'profile', 'jobs', 'applications', 'notifications'], true);
if ($isStudentWorkspace) {
    try {
        $db = Database::connection();
        $statement = $db->prepare('SELECT * FROM resumes WHERE student_id = ? ORDER BY created_at DESC LIMIT 1');
        $statement->execute([(int) $user['id']]);
        $resume = $statement->fetch() ?: null;

        $statement = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
        $statement->execute([(int) $user['id']]);
        $unreadNotifications = (int) $statement->fetchColumn();

        $profileRow = $db->prepare('SELECT * FROM student_profiles WHERE user_id = ?');
        $profileRow->execute([(int) $user['id']]);
        $profileForStrength = $profileRow->fetch() ?: [];
        $skillCount = $db->prepare('SELECT COUNT(*) FROM student_skills WHERE student_id = ?');
        $skillCount->execute([(int) $user['id']]);
        $hasSkills = (int) $skillCount->fetchColumn() > 0;
        $checks = [
            'Academic details' => trim((string) ($profileForStrength['branch'] ?? '')) !== ''
                && trim((string) ($profileForStrength['degree'] ?? '')) !== ''
                && trim((string) ($profileForStrength['graduation_year'] ?? '')) !== '',
            'CGPA recorded' => trim((string) ($profileForStrength['cgpa'] ?? '')) !== '',
            'Contact number' => trim((string) ($profileForStrength['phone'] ?? '')) !== '',
            'Short introduction' => trim((string) ($profileForStrength['bio'] ?? '')) !== '',
            'Skills added' => $hasSkills,
            'PDF resume' => $resume !== null,
        ];
        $profileStrengthParts = $checks;
        $profileStrength = (int) round((count(array_filter($checks)) / max(1, count($checks))) * 100);

        $timelineStatement = $db->prepare(
            'SELECT h.old_status, h.new_status, h.note, h.created_at, j.title, c.name AS company_name '
            . 'FROM application_status_history h JOIN applications a ON a.id = h.application_id '
            . 'JOIN jobs j ON j.id = a.job_id JOIN companies c ON c.id = j.company_id '
            . 'WHERE a.student_id = ? ORDER BY h.created_at DESC LIMIT 40',
        );
        $timelineStatement->execute([(int) $user['id']]);
        $applicationTimeline = $timelineStatement->fetchAll();

        if ($page === 'notifications' || $page === 'dashboard') {
            $noticeStatement = $db->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ' . ($page === 'notifications' ? '100' : '4'));
            $noticeStatement->execute([(int) $user['id']]);
            $notifications = $noticeStatement->fetchAll();
        }

        // Scheduled interviews and offers the student is the subject of. The
        // recruiter records both, so without this the person it concerns could
        // only ever learn about them from an inbox message they might have missed.
        $interviewStatement = $db->prepare(
            "SELECT i.id, i.scheduled_at, i.mode, i.location_or_link, j.title, c.name AS company_name "
            . 'FROM interviews i JOIN applications a ON a.id = i.application_id '
            . 'JOIN jobs j ON j.id = a.job_id JOIN companies c ON c.id = j.company_id '
            . "WHERE a.student_id = ? AND i.status = 'scheduled' AND i.scheduled_at >= NOW() "
            . 'ORDER BY i.scheduled_at LIMIT 3',
        );
        $interviewStatement->execute([(int) $user['id']]);
        $upcomingInterviews = $interviewStatement->fetchAll(PDO::FETCH_ASSOC);

        // `offers` arrives through a migration, so an older database must still
        // render the rest of the workspace instead of erroring.
        try {
            $offerStatement = $db->prepare(
                'SELECT o.ctc_lpa, o.offer_date, o.status, o.joined_on, j.title, c.name AS company_name '
                . 'FROM offers o JOIN applications a ON a.id = o.application_id '
                . 'JOIN jobs j ON j.id = a.job_id JOIN companies c ON c.id = j.company_id '
                . 'WHERE a.student_id = ? ORDER BY o.offer_date DESC, o.id DESC',
            );
            $offerStatement->execute([(int) $user['id']]);
            $studentOffers = $offerStatement->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $exception) {
            $studentOffers = [];
            error_log('Student offers query failure: ' . $exception->getMessage());
        }
    } catch (Throwable $exception) {
        error_log('Student workspace data failure: ' . $exception->getMessage());
    }
}

if ($page === 'dashboard' && $user) {
    try {
        $db = Database::connection();
        $counts = [
            'jobs' => (int) $db
                ->query(
                    "SELECT COUNT(*) FROM jobs WHERE status='published' AND (closes_at IS NULL OR closes_at>=NOW())",
                )
                ->fetchColumn(),
        ];
        if ($user['role'] === 'student') {
            $s = $db->prepare('SELECT * FROM student_profiles WHERE user_id=?');
            $s->execute([$user['id']]);
            $profile = $s->fetch() ?: [];
            $a = $db->prepare(
                'SELECT COUNT(*) FROM applications WHERE student_id=?',
            );
            $a->execute([$user['id']]);
            $counts['applications'] = (int) $a->fetchColumn();
        } elseif ($user['role'] === 'recruiter') {
            $s = $db->prepare(
                'SELECT COUNT(*) FROM jobs j JOIN recruiter_profiles r ON r.company_id=j.company_id WHERE r.user_id=?',
            );
            $s->execute([$user['id']]);
            $counts['my_jobs'] = (int) $s->fetchColumn();
        } else {
            $counts['users'] = (int) $db
                ->query('SELECT COUNT(*) FROM users')
                ->fetchColumn();
            $counts['applications'] = (int) $db
                ->query('SELECT COUNT(*) FROM applications')
                ->fetchColumn();
        }
    } catch (Throwable $e) {
        $dbError = true;
    }
}
$knownPages = [
    'home',
    'login',
    'register',
    'dashboard',
    'profile',
    'jobs',
    'applications',
    'notifications',
    'forbidden',
];
if (!in_array($page, $knownPages, true)) {
    http_response_code(404);
}
/**
 * Bottom water dock for the student workspace. One continuous capsule,
 * five destinations, the same material as the recruiter and admin docks.
 */
function sviet_student_dock(string $active, int $unread): void
{
    // The markup (and the tab list) lives in one partial so the career studio
    // shell renders exactly the same dock.
    require __DIR__ . '/views/partials/student-dock-markup.php';
}

function e(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Prepare shared layout data and a page-specific browser title.
$csrf = Security::csrfToken();
$appCssVersion = asset_version();
$pageTitle = match ($page) {
    'home' => 'SVIET Placement Portal',
    'login' => 'Sign in | SVIET Placement Portal',
    'register' => 'Create account | SVIET Placement Portal',
    'dashboard' => 'Dashboard | SVIET Placement Portal',
    'profile' => 'Student profile | SVIET Placement Portal',
    'jobs' => 'Opportunities | SVIET Placement Portal',
    'applications' => 'Applications | SVIET Placement Portal',
    'notifications' => 'Notifications | SVIET Placement Portal',
    'forbidden' => 'Access restricted | SVIET Placement Portal',
    default => 'Page not found | SVIET Placement Portal',
};
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#edf8fc">
    <meta name="description" content="A placement portal for Swami Vivekanand Institute of Engineering and Technology. Explore opportunities, manage applications, and connect students, recruiters, and the Training &amp; Placement Cell.">
    <title><?= e($pageTitle) ?></title>
    <script>
        /* Apply the stored appearance before first paint so neither theme flashes. */
        (function () {
            try {
                var saved = localStorage.getItem('sviet-theme');
                var theme = saved === 'dark' || saved === 'light' ? saved : 'light';
                document.documentElement.dataset.theme = theme;
                document.documentElement.style.colorScheme = theme;
            } catch (error) {
                document.documentElement.dataset.theme = 'light';
            }
        })();
    </script>
    <link rel="stylesheet" href="assets/css/theme.css?v=<?= e($appCssVersion) ?>">
    <link rel="stylesheet" href="assets/css/app.css?v=<?= e($appCssVersion) ?>">
    <?php if ($page === 'home'): ?>
        <link rel="stylesheet" href="assets/css/homepage.css?v=<?= e($appCssVersion) ?>">
    <?php endif; ?>
    <?php if ($isStudentWorkspace): ?>
        <link rel="stylesheet" href="assets/css/water-dock.css?v=<?= e($appCssVersion) ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="assets/css/liquid-glass.css?v=<?= e($appCssVersion) ?>">
    <link rel="stylesheet" href="assets/css/logicbloom.css?v=<?= e($appCssVersion) ?>">
</head>
<body class="<?= $page === 'home' ? 'homepage' : '' ?>">
<?php require __DIR__ . '/views/partials/glass-defs.php'; ?>
<a class="skip-link" href="#main-content">Skip to main content</a>
<header class="bubble-top" aria-label="SVIET Training and Placement">
    <a class="bubble bubble--brand" href="index.php" aria-label="Swami Vivekanand Institute of Engineering and Technology home">
        <span class="bubble-brand-mark" aria-hidden="true">S</span>
        <span class="bubble-brand-copy">
            <strong>SVIET</strong>
            <small>Training &amp; Placement</small>
        </span>
    </a>
    <div class="bubble-cluster">
        <button class="bubble bubble--icon" type="button" data-theme-toggle aria-pressed="false" aria-label="Switch appearance">
            <span class="theme-toggle__track" aria-hidden="true">
                <svg class="theme-toggle__icon theme-toggle__icon--sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><circle cx="12" cy="12" r="4.2"/><path d="M12 2.5v2.2M12 19.3v2.2M4.2 4.2l1.6 1.6M18.2 18.2l1.6 1.6M2.5 12h2.2M19.3 12h2.2M4.2 19.8l1.6-1.6M18.2 5.8l1.6-1.6"/></svg>
                <svg class="theme-toggle__icon theme-toggle__icon--moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M20 14.4A8.2 8.2 0 0 1 9.6 4a8.4 8.4 0 1 0 10.4 10.4Z"/></svg>
            </span>
        </button>
        <?php if ($user): ?>
            <span class="bubble bubble--account" title="<?= e((string) $user['name']) ?>" aria-label="Signed in as <?= e((string) $user['name']) ?>">
                <?= e(strtoupper(mb_substr((string) $user['name'], 0, 1))) ?>
            </span>
        <?php else: ?>
            <a class="bubble bubble--cta" href="index.php?page=login">Sign in</a>
        <?php endif; ?>
        <button class="bubble bubble--icon" type="button" data-bubble-menu aria-expanded="false" aria-controls="bubble-menu" aria-label="Open menu">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h10"/></svg>
        </button>
    </div>
</header>

<div class="bubble-sheet" id="bubble-menu" data-bubble-sheet role="dialog" aria-modal="true" aria-labelledby="bubble-menu-title" hidden>
    <div class="bubble-sheet__panel">
        <div class="bubble-sheet__head">
            <strong id="bubble-menu-title">Menu</strong>
            <button class="bubble bubble--icon" type="button" data-bubble-close aria-label="Close menu">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>
        <nav class="bubble-sheet__links" aria-label="Main navigation">
            <a href="index.php#portal-workspaces"><span class="bubble-sheet__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/></svg></span>Portal workspaces</a>
            <a href="index.php#how-it-works"><span class="bubble-sheet__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3v4M12 17v4M3 12h4M17 12h4M5.6 5.6l2.8 2.8M15.6 15.6l2.8 2.8M18.4 5.6l-2.8 2.8M8.4 15.6l-2.8 2.8"/></svg></span>How it works</a>
            <a href="index.php#for-recruiters"><span class="bubble-sheet__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="7" width="18" height="14" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg></span>For recruiters</a>
            <?php if ($user): ?>
                <a href="index.php?page=dashboard"><span class="bubble-sheet__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 11.2 12 4l8 7.2V20a1 1 0 0 1-1 1h-4.6v-5.6H9.6V21H5a1 1 0 0 1-1-1z"/></svg></span>Dashboard</a>
                <?php if ($user['role'] === 'student'): ?>
                    <a href="index.php?page=profile"><span class="bubble-sheet__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8.2" r="3.7"/><path d="M4.8 20.6a7.2 7.2 0 0 1 14.4 0"/></svg></span>My profile</a>
                    <a href="index.php?page=jobs"><span class="bubble-sheet__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="7" width="18" height="14" rx="2"/><path d="M8.5 7V5.2A1.7 1.7 0 0 1 10.2 3.5h3.6a1.7 1.7 0 0 1 1.7 1.7V7"/></svg></span>Opportunities</a>
                    <a href="index.php?page=applications"><span class="bubble-sheet__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M7 3h7l4 4v14H7z"/><path d="M14 3v5h4"/></svg></span>Applications</a>
                    <a href="career.php"><span class="bubble-sheet__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 19V9M10 19V4M16 19v-7M22 19H2"/></svg></span>Career insights</a>
                    <a href="resume-review.php"><span class="bubble-sheet__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M7 3h7l4 4v14H7z"/><path d="M14 3v5h4M9.5 13h5M9.5 17h3.5"/></svg></span>Resume review</a>
                    <a href="practice.php"><span class="bubble-sheet__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3a3.4 3.4 0 0 1 3.4 3.4v4.3a3.4 3.4 0 0 1-6.8 0V6.4A3.4 3.4 0 0 1 12 3Z"/><path d="M5.5 11.5a6.5 6.5 0 0 0 13 0M12 18v3M8.5 21h7"/></svg></span>Interview practice</a>
                <?php elseif ($user['role'] === 'recruiter'): ?>
                    <a href="recruiter.php"><span class="bubble-sheet__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="7" width="18" height="14" rx="2"/><path d="M3 12h18"/></svg></span>Recruiter workspace</a>
                <?php elseif (in_array($user['role'], ['admin', 'tpo'], true)): ?>
                    <a href="admin.php"><span class="bubble-sheet__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3 4.5 6v5.5c0 4.6 3.1 8.4 7.5 9.5 4.4-1.1 7.5-4.9 7.5-9.5V6z"/></svg></span>TPO / Admin</a>
                <?php endif; ?>
            <?php endif; ?>
        </nav>
        <div class="bubble-sheet__foot">
            <?php if ($user): ?>
                <form method="post" action="index.php" class="inline-form w-100">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="logout">
                    <button type="submit" class="w-100">Sign out</button>
                </form>
            <?php else: ?>
                <nav class="bubble-sheet__links" aria-label="Account">
                    <a href="index.php?page=login"><span class="bubble-sheet__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 8l4 4-4 4M14 12H3"/></svg></span>Portal sign in</a>
                    <a href="index.php?page=register"><span class="bubble-sheet__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 5v14M5 12h14"/></svg></span>Create account</a>
                </nav>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php if (($error || $success) && $page !== 'login' && $page !== 'register'): ?>
    <div class="container pt-3">
        <div class="alert <?= $error
            ? 'alert-danger'
            : 'alert-success' ?>" role="<?= $error
    ? 'alert'
    : 'status' ?>" aria-live="<?= $error ? 'assertive' : 'polite' ?>">
            <?= e($error ?: $success) ?>
        </div>
    </div>
<?php endif; ?>
<?php if ($page === 'home'): ?>
<main id="main-content" class="lb-home">
    <section class="lb-hero" id="home" aria-labelledby="home-title">
        <div class="lb-main lb-hero__grid">
            <div class="lb-hero__copy" data-reveal>
                <p class="lb-eyebrow">
                    <span class="lb-eyebrow__dot" aria-hidden="true"></span>
                    SVIET Training &amp; Placement Portal
                </p>
                <h1 id="home-title">SVIET placements<br><span>in one place.</span></h1>
                <p class="lb-lede">
                    Student profiles, approved opportunities, and application updates—supported by the SVIET Training &amp; Placement Cell.
                </p>

                <div class="lb-actions">
                    <?php if (!$user): ?>
                        <a class="lb-btn lb-btn--primary" href="index.php?page=login">
                            Sign in to the portal
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17 17 7M9 7h8v8"/></svg>
                        </a>
                        <a class="lb-btn lb-btn--ghost" href="index.php?page=register">Create an account</a>
                    <?php elseif ($user['role'] === 'student'): ?>
                        <a class="lb-btn lb-btn--primary" href="index.php?page=dashboard">
                            Open your dashboard
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17 17 7M9 7h8v8"/></svg>
                        </a>
                        <a class="lb-btn lb-btn--ghost" href="index.php?page=jobs">Browse opportunities</a>
                    <?php elseif ($user['role'] === 'recruiter'): ?>
                        <a class="lb-btn lb-btn--primary" href="recruiter.php">
                            Open recruiter workspace
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17 17 7M9 7h8v8"/></svg>
                        </a>
                    <?php else: ?>
                        <a class="lb-btn lb-btn--primary" href="admin.php">
                            Open TPO / Admin workspace
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17 17 7M9 7h8v8"/></svg>
                        </a>
                    <?php endif; ?>
                </div>

                <div class="lb-audience" role="group" aria-label="Portal access for students, recruiters, and the Training and Placement Cell">
                    <span class="lb-audience__label">Serving the SVIET community</span>
                    <span class="lb-pill"><i aria-hidden="true"></i>Students</span>
                    <span class="lb-pill"><i aria-hidden="true"></i>Recruiters</span>
                    <span class="lb-pill"><i aria-hidden="true"></i>Placement Cell</span>
                </div>
            </div>

            <aside class="lb-hero__card" id="how-it-works" data-reveal aria-label="Student placement workflow">
                <div class="lb-card__head">
                    <span>Student workflow</span>
                    <span class="lb-card__status">SVIET placement portal</span>
                </div>
                <h2>From profile to<br>opportunity.</h2>
                <ol class="lb-steps">
                    <li>
                        <span class="lb-steps__index" aria-hidden="true">01</span>
                        <span class="lb-steps__copy">
                            <strong>Build your profile</strong>
                            <small>Add your academic details and skills.</small>
                        </span>
                    </li>
                    <li>
                        <span class="lb-steps__index" aria-hidden="true">02</span>
                        <span class="lb-steps__copy">
                            <strong>Explore approved opportunities</strong>
                            <small>Review roles and their eligibility criteria.</small>
                        </span>
                    </li>
                    <li>
                        <span class="lb-steps__index" aria-hidden="true">03</span>
                        <span class="lb-steps__copy">
                            <strong>Track your applications</strong>
                            <small>Follow updates in your workspace.</small>
                        </span>
                    </li>
                </ol>
                <div class="lb-card__foot">
                    <span aria-hidden="true"></span>
                    <span>A dedicated placement portal for the SVIET community.</span>
                </div>
            </aside>
        </div>
    </section>

    <section class="lb-section" id="portal-workspaces" aria-labelledby="portal-workspaces-title">
        <div class="lb-main">
            <div class="lb-section__head" data-reveal>
                <div>
                    <p class="lb-section__eyebrow">One institute. Connected journeys.</p>
                    <h2 id="portal-workspaces-title">A dedicated portal for <span>every role.</span></h2>
                </div>
                <p>One SVIET-supported workspace for students, recruiting organizations, and authorized Training &amp; Placement staff.</p>
            </div>

            <div class="lb-grid">
                <article class="lb-card" data-reveal>
                    <span class="lb-icon" aria-hidden="true">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 9-5 9 5-9 5-9-5Z"/><path d="M7 11.2v5.1c3.2 2.2 6.8 2.2 10 0v-5.1M21 9v5"/></svg>
                    </span>
                    <h3>Student placement portal</h3>
                    <p>Maintain your profile, review approved opportunities, apply for eligible roles, and follow application progress.</p>
                    <?php if ($user && $user['role'] === 'student'): ?>
                        <a class="lb-card__link" href="index.php?page=jobs">Browse opportunities <span aria-hidden="true">&#8599;</span></a>
                    <?php else: ?>
                        <a class="lb-card__link" href="index.php?page=login">Student sign in <span aria-hidden="true">&#8599;</span></a>
                    <?php endif; ?>
                </article>

                <article class="lb-card" id="for-recruiters" data-reveal>
                    <span class="lb-icon" aria-hidden="true">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="7" width="18" height="14" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M3 12h18m-11 0v2h4v-2"/></svg>
                    </span>
                    <h3>Recruiter access</h3>
                    <p>Request recruiter access, then submit a company profile for Training &amp; Placement review before managing opportunities and applicants.</p>
                    <?php if ($user && $user['role'] === 'recruiter'): ?>
                        <a class="lb-card__link" href="recruiter.php">Open recruiter workspace <span aria-hidden="true">&#8599;</span></a>
                    <?php else: ?>
                        <a class="lb-card__link" href="index.php?page=register&amp;role=recruiter">Request recruiter access <span aria-hidden="true">&#8599;</span></a>
                    <?php endif; ?>
                </article>

                <article class="lb-card" data-reveal>
                    <span class="lb-icon" aria-hidden="true">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3h7l4 4v14H5V6a3 3 0 0 1 3-3Z"/><path d="M15 3v5h5M8 13h8M8 17h5"/></svg>
                    </span>
                    <h3>Training &amp; Placement Cell</h3>
                    <p>Authorized staff can review recruiter access, moderate opportunities, and monitor placement activity.</p>
                    <?php if (
                        $user &&
                        in_array($user['role'], ['admin', 'tpo'], true)
                    ): ?>
                        <a class="lb-card__link" href="admin.php">Open TPO workspace <span aria-hidden="true">&#8599;</span></a>
                    <?php else: ?>
                        <a class="lb-card__link" href="index.php?page=login">Authorized staff sign in <span aria-hidden="true">&#8599;</span></a>
                    <?php endif; ?>
                </article>
            </div>
        </div>
    </section>

    <section class="lb-section" aria-labelledby="placement-cell-title">
        <div class="lb-main">
            <div class="lb-band" data-reveal>
                <div>
                    <p class="lb-section__eyebrow">Training &amp; Placement Cell</p>
                    <h2 id="placement-cell-title">Built for the people who run placements.</h2>
                    <p>Every opportunity reaches students through the same review chain, so what a student sees has already been checked by the placement cell.</p>
                    <ul class="lb-list">
                        <li>
                            <span class="lb-list__tick" aria-hidden="true">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 4.5 4.5L19 7"/></svg>
                            </span>
                            <span>Only published opportunities from approved companies appear to students.</span>
                        </li>
                        <li>
                            <span class="lb-list__tick" aria-hidden="true">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 4.5 4.5L19 7"/></svg>
                            </span>
                            <span>Your role is read from your account, never chosen at sign-in.</span>
                        </li>
                        <li>
                            <span class="lb-list__tick" aria-hidden="true">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 4.5 4.5L19 7"/></svg>
                            </span>
                            <span>Recruiter decisions, job moderation and student records are recorded in the audit log.</span>
                        </li>
                    </ul>
                </div>
                <div class="lb-band__rules" aria-label="How an opportunity reaches students">
                    <div class="lb-rule">
                        <strong>1. Recruiter account reviewed</strong>
                        <small>A new recruiter account stays pending until the placement cell approves it.</small>
                    </div>
                    <div class="lb-rule">
                        <strong>2. Company profile checked</strong>
                        <small>Company details are verified before any role can be published.</small>
                    </div>
                    <div class="lb-rule">
                        <strong>3. Role published or moderated</strong>
                        <small>Approved companies publish directly; the placement cell can still moderate a listing.</small>
                    </div>
                </div>
            </div>

            <footer class="lb-footer">
                <strong>Swami Vivekanand Institute of Engineering and Technology</strong>
                <span>SVIET Training &amp; Placement Portal &middot; <?= date('Y') ?></span>
            </footer>
        </div>
    </section>
</main>

<?php elseif ($page === 'login' || $page === 'register'): ?>
<?php $isLogin = $page === 'login'; ?>
<main id="main-content" class="auth-wrap">
    <div class="auth-shell">
        <section class="auth-intro" aria-labelledby="auth-page-title">
            <p class="eyebrow">SVIET Training &amp; Placement</p>
            <h1 id="auth-page-title">
                <?php if ($isLogin): ?>
                    One sign-in.<br><span>Three workspaces.</span>
                <?php else: ?>
                    One account.<br><span>The right workspace.</span>
                <?php endif; ?>
            </h1>
            <p class="auth-intro__lede">
                A single, role-aware portal for the whole placement journey at Swami Vivekanand Institute of Engineering and Technology.
            </p>

            <ul class="auth-role-note" aria-label="Who uses this portal">
                <li class="auth-role-item">
                    <span class="auth-role-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 9-5 9 5-9 5-9-5Z"/><path d="M7 11.2v5.1c3.2 2.2 6.8 2.2 10 0v-5.1M21 9v5"/></svg>
                    </span>
                    <span class="auth-role-copy">
                        <strong>Students</strong>
                        <small>Profile, eligible openings, applications and alerts.</small>
                    </span>
                </li>
                <li class="auth-role-item">
                    <span class="auth-role-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="7" width="18" height="14" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M3 12h18m-11 0v2h4v-2"/></svg>
                    </span>
                    <span class="auth-role-copy">
                        <strong>Recruiters</strong>
                        <small>Company profile, job listings, applicants and interviews.</small>
                    </span>
                </li>
                <li class="auth-role-item">
                    <span class="auth-role-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3h7l4 4v14H5V6a3 3 0 0 1 3-3Z"/><path d="M15 3v5h5M8 13h8M8 17h5"/></svg>
                    </span>
                    <span class="auth-role-copy">
                        <strong>Training &amp; Placement Cell</strong>
                        <small>Approvals, placement records and institute reports.</small>
                    </span>
                </li>
            </ul>

            <p class="auth-intro__foot">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4.5" y="10.5" width="15" height="9.5" rx="2.5"/><path d="M8 10.5V7.6a4 4 0 0 1 8 0v2.9"/></svg>
                Session-based access. Your role is read from your account, never chosen at sign-in.
            </p>
        </section>

        <section class="auth-card" aria-labelledby="auth-title">
            <header class="auth-card__head">
                <p class="eyebrow"><?= $isLogin ? 'Welcome back' : 'Create an account' ?></p>
                <h2 id="auth-title"><?= $isLogin ? 'Sign in' : 'Register' ?></h2>
                <p class="muted">
                    <?= $isLogin
                        ? 'Use the email address your account was created with. You will land in the workspace that matches your role.'
                        : 'Choose the account type that matches your placement role. Recruiter accounts need Training &amp; Placement approval before first sign-in.' ?>
                </p>
            </header>

            <?php if ($error || $success): ?>
                <div class="alert <?= $error ? 'alert-danger' : 'alert-success' ?> auth-alert"
                     role="<?= $error ? 'alert' : 'status' ?>"
                     aria-live="<?= $error ? 'assertive' : 'polite' ?>">
                    <?= e($error ?: $success) ?>
                </div>
            <?php endif; ?>

            <form method="post" class="auth-form">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="<?= $isLogin ? 'login' : 'register' ?>">

                <?php if (!$isLogin): ?>
                    <div class="auth-field">
                        <label for="account-name">Full name</label>
                        <input id="account-name" class="form-control" name="name" required maxlength="120" autocomplete="name" placeholder="As printed on your institute record">
                    </div>
                <?php endif; ?>

                <div class="auth-field">
                    <label for="account-email">Email address</label>
                    <input id="account-email" class="form-control" type="email" name="email" required maxlength="190" autocomplete="email"
                           inputmode="email" placeholder="you@example.com" spellcheck="false">
                </div>

                <div class="auth-field">
                    <div class="auth-field__label">
                        <label for="account-password">Password</label>
                        <?php if (!$isLogin): ?>
                            <span class="auth-hint">At least 10 characters</span>
                        <?php endif; ?>
                    </div>
                    <div class="auth-password">
                        <input id="account-password" class="form-control" type="password" name="password" required
                               minlength="<?= $isLogin ? '1' : '10' ?>"
                               autocomplete="<?= $isLogin ? 'current-password' : 'new-password' ?>"
                               placeholder="<?= $isLogin ? 'Your password' : 'Choose a strong password' ?>"
                               <?= $isLogin ? '' : 'aria-describedby="account-password-help"' ?>>
                        <button type="button" class="auth-reveal" data-password-toggle="account-password"
                                aria-controls="account-password" aria-pressed="false" aria-label="Show password" hidden>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                            <span class="sr-only">Show password</span>
                        </button>
                    </div>
                    <?php if (!$isLogin): ?>
                        <small id="account-password-help" class="auth-help">Mix letters, numbers and a symbol. Never reuse your institute password.</small>
                    <?php endif; ?>
                </div>

                <?php if (!$isLogin): ?>
                    <fieldset class="auth-field auth-roles-field">
                        <legend>Account type</legend>
                        <div class="auth-role-choices">
                            <label class="auth-choice">
                                <input type="radio" name="role" value="student" required <?= $selectedRole === 'student' ? 'checked' : '' ?>>
                                <span class="auth-choice__body">
                                    <span class="auth-choice__icon" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 9-5 9 5-9 5-9-5Z"/><path d="M7 11.2v5.1c3.2 2.2 6.8 2.2 10 0v-5.1"/></svg>
                                    </span>
                                    <span class="auth-choice__copy">
                                        <strong>Student</strong>
                                        <small>Immediate access</small>
                                    </span>
                                </span>
                            </label>
                            <label class="auth-choice">
                                <input type="radio" name="role" value="recruiter" required <?= $selectedRole === 'recruiter' ? 'checked' : '' ?>>
                                <span class="auth-choice__body">
                                    <span class="auth-choice__icon" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="7" width="18" height="14" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M3 12h18m-11 0v2h4v-2"/></svg>
                                    </span>
                                    <span class="auth-choice__copy">
                                        <strong>Recruiter</strong>
                                        <small>Needs approval</small>
                                    </span>
                                </span>
                            </label>
                        </div>
                        <small class="auth-help" id="account-role-help">Approved recruiters submit a company profile for a second review before publishing roles.</small>
                    </fieldset>
                <?php endif; ?>

                <button class="primary-btn w-100 auth-submit" type="submit"
                        data-busy-label="<?= $isLogin ? 'Signing in…' : 'Creating account…' ?>">
                    <?= $isLogin ? 'Sign in' : 'Create account' ?> <span class="btn-arrow" aria-hidden="true">↗</span>
                </button>
            </form>

            <p class="auth-switch">
                <?= $isLogin ? 'New to the portal?' : 'Already registered?' ?>
                <a href="index.php?page=<?= $isLogin ? 'register' : 'login' ?>"><?= $isLogin ? 'Create an account' : 'Sign in' ?></a>
            </p>
            <a class="auth-back-link" href="index.php">Back to the SVIET portal</a>
        </section>
    </div>
</main>
<?php elseif ($page === 'profile' && $user && $user['role'] === 'student'): ?>
<main id="main-content" class="dashboard container">
    <div class="dash-top">
        <div>
            <p class="eyebrow">STUDENT WORKSPACE</p>
            <h1>Your profile.</h1>
            <p class="muted">Keep your academic details and skills current for more useful career-fit results.</p>
        </div>
        <a class="secondary-btn" href="index.php?page=dashboard">Back to dashboard</a>
    </div>

    <?php if (!empty($profileError)): ?>
        <div class="empty-state mt-4">
            Your profile information is temporarily unavailable. Please try again later.
        </div>
    <?php else: ?>
        <section class="panel mt-4" aria-labelledby="profile-details-title">
            <h2 id="profile-details-title">Academic and personal details</h2>
            <form method="post" class="auth-form">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="save_profile">

                <label for="profile-name">
                    Full name
                    <input id="profile-name" class="form-control" name="name" required maxlength="120" value="<?= e(
                        (string) ($profile['name'] ?? $user['name']),
                    ) ?>">
                </label>
                <label for="profile-email">
                    Email address
                    <input id="profile-email" class="form-control" value="<?= e(
                        (string) ($profile['email'] ?? $user['email']),
                    ) ?>" disabled>
                    <small>Email changes are not supported in this form.</small>
                </label>
                <label for="profile-phone">
                    Phone
                    <input id="profile-phone" class="form-control" name="phone" maxlength="30" value="<?= e(
                        (string) ($profile['phone'] ?? ''),
                    ) ?>">
                </label>
                <label for="profile-degree">
                    Degree
                    <input id="profile-degree" class="form-control" name="degree" maxlength="120" placeholder="e.g. B.Tech Computer Science" value="<?= e(
                        (string) ($profile['degree'] ?? ''),
                    ) ?>">
                </label>
                <label for="profile-branch">
                    Branch
                    <input id="profile-branch" class="form-control" name="branch" maxlength="120" placeholder="e.g. Computer Engineering" value="<?= e(
                        (string) ($profile['branch'] ?? ''),
                    ) ?>">
                </label>

                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="profile-semester">
                            Semester
                            <input id="profile-semester" class="form-control" type="number" min="1" max="20" name="semester" value="<?= e(
                                (string) ($profile['semester'] ?? ''),
                            ) ?>">
                        </label>
                    </div>
                    <div class="col-md-4">
                        <label for="profile-cgpa">
                            CGPA (0–10)
                            <input id="profile-cgpa" class="form-control" type="number" min="0" max="10" step="0.01" name="cgpa" value="<?= e(
                                (string) ($profile['cgpa'] ?? ''),
                            ) ?>">
                        </label>
                    </div>
                    <div class="col-md-4">
                        <label for="profile-graduation-year">
                            Graduation year
                            <input id="profile-graduation-year" class="form-control" type="number" min="2000" max="2150" name="graduation_year" value="<?= e(
                                (string) ($profile['graduation_year'] ?? ''),
                            ) ?>">
                        </label>
                    </div>
                </div>

                <label for="profile-bio">
                    About you
                    <textarea id="profile-bio" class="form-control" name="bio" rows="4" maxlength="3000" placeholder="A short professional introduction"><?= e(
                        (string) ($profile['bio'] ?? ''),
                    ) ?></textarea>
                </label>
                <button class="primary-btn" type="submit">Save profile <span aria-hidden="true">↗</span></button>
            </form>
        </section>

        <section class="panel mt-4" id="resume" aria-labelledby="profile-resume-title">
            <div class="strength-head">
                <div>
                    <p class="eyebrow">RESUME</p>
                    <h2 id="profile-resume-title">PDF resume</h2>
                </div>
                <?php if ($resume): ?>
                    <span class="status-pill">Uploaded <?= e(date('d M Y', strtotime((string) $resume['created_at']))) ?></span>
                <?php endif; ?>
            </div>
            <?php if ($resume): ?>
                <p class="muted">
                    <strong><?= e((string) $resume['original_name']) ?></strong>
                    · <?= e(number_format(((int) $resume['file_size']) / 1024, 0)) ?> KB
                </p>
                <div class="resume-actions">
                    <a class="secondary-btn" href="index.php?page=resume&amp;id=<?= (int) $resume['id'] ?>" target="_blank" rel="noopener">View PDF</a>
                    <a class="secondary-btn" href="index.php?page=resume&amp;id=<?= (int) $resume['id'] ?>&amp;download=1">Download</a>
                </div>
            <?php else: ?>
                <p class="muted">No resume on file yet. Recruiters can only review what you upload.</p>
            <?php endif; ?>
            <form method="post" enctype="multipart/form-data" class="auth-form resume-form">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="upload_resume">
                <label for="profile-resume">
                    <?= $resume ? 'Replace with a new PDF' : 'Upload your resume' ?>
                    <input id="profile-resume" class="form-control" type="file" name="resume" accept="application/pdf,.pdf" required>
                    <small>PDF only, up to 5 MB. The newest upload replaces the previous file.</small>
                </label>
                <button class="primary-btn" type="submit"><?= $resume ? 'Replace resume' : 'Upload resume' ?> <span aria-hidden="true">↗</span></button>
            </form>
            <?php if ($resume): ?>
                <form method="post" class="inline-form resume-delete">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="delete_resume">
                    <button class="secondary-btn" type="submit">Remove resume</button>
                </form>
            <?php endif; ?>
        </section>

        <section class="panel mt-4" aria-labelledby="profile-skills-title">
            <h2 id="profile-skills-title">Skills</h2>
            <p class="muted">Add skills that best describe your experience, separated by commas.</p>
            <form method="post" class="auth-form">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="save_skills">
                <label for="profile-skills">
                    Your skills
                    <textarea id="profile-skills" class="form-control" name="skills" rows="3" maxlength="2000" placeholder="PHP, MySQL, JavaScript, Git"><?= e(
                        implode(', ', $profileSkills ?? []),
                    ) ?></textarea>
                </label>
                <button class="primary-btn" type="submit">Save skills <span aria-hidden="true">↗</span></button>
            </form>
        </section>
    <?php endif; ?>
</main>
<?php elseif ($page === 'jobs' && $user && $user['role'] === 'student'): ?>
<main id="main-content" class="dashboard container">
    <div class="dash-top">
        <div>
            <p class="eyebrow">STUDENT WORKSPACE</p>
            <h1>Open opportunities.</h1>
            <p class="muted">Review published opportunities from approved companies and check each role's eligibility criteria.</p>
        </div>
        <a class="secondary-btn" href="index.php?page=applications">My applications</a>
    </div>

    <?php if (!empty($jobsError)): ?>
        <div class="empty-state mt-4">
            Opportunities are temporarily unavailable. Please try again later.
        </div>
    <?php elseif (empty($jobs)): ?>
        <div class="empty-state mt-4">
            <h2>No open jobs yet</h2>
            <p>Approved companies have not published any current opportunities.</p>
        </div>
    <?php else: ?>
        <div class="row g-3 mt-2">
            <?php foreach ($jobs as $job): ?>
                <div class="col-12">
                    <article class="panel">
                        <div class="d-flex justify-content-between gap-3 flex-wrap">
                            <div>
                                <p class="eyebrow"><?= e(
                                    $job['company_name'],
                                ) ?></p>
                                <h2><?= e($job['title']) ?></h2>
                                <p class="muted">
                                    <?= e(
                                        $job['location'] ??
                                            'Location not specified',
                                    ) ?>
                                    ·
                                    <?= e(
                                        $job['employment_type'] ??
                                            'Employment type not specified',
                                    ) ?>
                                </p>
                            </div>
                            <span class="status-pill">Minimum CGPA: <?= e(
                                (string) $job['min_cgpa'],
                            ) ?></span>
                        </div>

                        <?php $thisJobSkills = $jobSkillMap[(int) $job['id']] ?? []; ?>
                        <?php if (!empty($thisJobSkills['required']) || !empty($thisJobSkills['preferred'])): ?>
                            <p class="sv-skill-row">
                                <?php foreach (($thisJobSkills['required'] ?? []) as $skillName): ?>
                                    <span class="sv-skill-chip"><?= e((string) $skillName) ?></span>
                                <?php endforeach; ?>
                                <?php foreach (($thisJobSkills['preferred'] ?? []) as $skillName): ?>
                                    <span class="sv-skill-chip sv-skill-chip--soft"><?= e((string) $skillName) ?><span class="sr-only"> (preferred)</span></span>
                                <?php endforeach; ?>
                            </p>
                        <?php else: ?>
                            <p class="muted">This employer has not listed required skills yet, so your career-fit score covers academics only.</p>
                        <?php endif; ?>

                        <p><?= nl2br(e($job['description'])) ?></p>
                        <?php if ($job['eligible_branch']): ?>
                            <p class="muted">Eligible branch: <?= e(
                                $job['eligible_branch'],
                            ) ?></p>
                        <?php endif; ?>

                        <?php if (
                            in_array((int) $job['id'], $appliedJobIds, true)
                        ): ?>
                            <span class="status-pill">Application submitted</span>
                        <?php else: ?>
                            <form method="post" class="auth-form">
                                <input type="hidden" name="_csrf" value="<?= e(
                                    $csrf,
                                ) ?>">
                                <input type="hidden" name="action" value="apply_job">
                                <input type="hidden" name="job_id" value="<?= (int) $job[
                                    'id'
                                ] ?>">
                                <label for="cover-note-<?= (int) $job['id'] ?>">
                                    Optional note to recruiter
                                    <textarea id="cover-note-<?= (int) $job[
                                        'id'
                                    ] ?>" class="form-control" name="cover_note" maxlength="2000" rows="2"></textarea>
                                </label>
                                <button class="primary-btn" type="submit">Apply now <span aria-hidden="true">↗</span></button>
                            </form>
                        <?php endif; ?>
                    </article>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>
<?php elseif (
    $page === 'applications' &&
    $user &&
    $user['role'] === 'student'
): ?>
<main id="main-content" class="dashboard container">
    <div class="dash-top">
        <div>
            <p class="eyebrow">STUDENT WORKSPACE</p>
            <h1>Your applications.</h1>
            <p class="muted">Track status updates for jobs you've applied to.</p>
        </div>
        <a class="secondary-btn" href="index.php?page=jobs">Browse jobs</a>
    </div>

    <?php if (!empty($applicationsError)): ?>
        <div class="empty-state mt-4">
            Your applications are temporarily unavailable. Please try again later.
        </div>
    <?php elseif (empty($applications)): ?>
        <div class="empty-state mt-4">
            <h2>No applications yet</h2>
            <p>When you apply to an open job, its current status will appear here.</p>
        </div>
    <?php else: ?>
        <div class="panel mt-4 table-responsive">
            <table class="table table-dark table-hover align-middle">
                <thead>
                    <tr>
                        <th scope="col">Role</th>
                        <th scope="col">Company</th>
                        <th scope="col">Applied</th>
                        <th scope="col">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($applications as $application): ?>
                        <tr>
                            <td>
                                <?= e($application['title']) ?>
                                <div class="muted"><?= e(
                                    $application['location'] ?? '',
                                ) ?></div>
                            </td>
                            <td><?= e($application['company_name']) ?></td>
                            <td><?= e($application['applied_at']) ?></td>
                            <td>
                                <span class="status-pill">
                                    <?= e(
                                        ucwords(
                                            str_replace(
                                                '_',
                                                ' ',
                                                $application['status'],
                                            ),
                                        ),
                                    ) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($upcomingInterviews): ?>
        <section class="panel mt-4" aria-labelledby="student-interviews-title">
            <div class="strength-head">
                <div>
                    <p class="eyebrow">INTERVIEWS</p>
                    <h2 id="student-interviews-title">Your scheduled interviews</h2>
                </div>
                <span class="status-pill">Next first</span>
            </div>
            <ul class="sv-next-list">
                <?php foreach ($upcomingInterviews as $interview): ?>
                    <?php $joinLink = trim((string) ($interview['location_or_link'] ?? '')); ?>
                    <li class="sv-next-item">
                        <div>
                            <strong><?= e((string) $interview['title']) ?></strong>
                            <span class="muted"> · <?= e((string) $interview['company_name']) ?></span>
                            <p class="muted">
                                <time datetime="<?= e(date('c', strtotime((string) $interview['scheduled_at']))) ?>"><?= e(date('D d M Y, H:i', strtotime((string) $interview['scheduled_at']))) ?></time>
                                <?php if (!empty($interview['mode'])): ?> · <?= e((string) $interview['mode']) ?><?php endif; ?>
                            </p>
                        </div>
                        <?php if ($joinLink !== '' && preg_match('~^https?://~i', $joinLink)): ?>
                            <a class="secondary-btn" href="<?= e($joinLink) ?>" target="_blank" rel="noopener noreferrer">Join link <span aria-hidden="true">↗</span></a>
                        <?php elseif ($joinLink !== ''): ?>
                            <span class="muted"><?= e($joinLink) ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <?php if ($studentOffers): ?>
        <section class="panel mt-4" aria-labelledby="student-offers-title">
            <div class="strength-head">
                <div>
                    <p class="eyebrow">OFFERS</p>
                    <h2 id="student-offers-title">Your offers</h2>
                </div>
                <span class="status-pill"><?= count($studentOffers) ?> recorded</span>
            </div>
            <div class="table-responsive">
                <table class="table table-dark table-hover align-middle">
                    <thead>
                        <tr>
                            <th scope="col">Role</th>
                            <th scope="col">Company</th>
                            <th scope="col">CTC</th>
                            <th scope="col">Status</th>
                            <th scope="col">Offer date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($studentOffers as $offer): ?>
                            <tr>
                                <td><?= e((string) $offer['title']) ?></td>
                                <td><?= e((string) $offer['company_name']) ?></td>
                                <td>
                                    <?= $offer['ctc_lpa'] !== null
                                        ? e(number_format((float) $offer['ctc_lpa'], 2)) . ' LPA'
                                        : 'Not disclosed' ?>
                                </td>
                                <td>
                                    <span class="status-pill"><?= e(ucfirst((string) $offer['status'])) ?></span>
                                    <?php if (!empty($offer['joined_on'])): ?>
                                        <div class="muted">Joined <?= e(date('d M Y', strtotime((string) $offer['joined_on']))) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= !empty($offer['offer_date']) ? e(date('d M Y', strtotime((string) $offer['offer_date']))) : '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <?php if (!empty($applicationTimeline)): ?>
            <section class="panel mt-4" aria-labelledby="application-timeline-title">
                <div class="strength-head">
                    <div>
                        <p class="eyebrow">TIMELINE</p>
                        <h2 id="application-timeline-title">Every stage change</h2>
                    </div>
                    <span class="status-pill">Most recent first</span>
                </div>
                <ol class="sv-timeline">
                    <?php foreach ($applicationTimeline as $event): ?>
                        <li>
                            <span class="sv-timeline-dot" aria-hidden="true"></span>
                            <div>
                                <strong><?= e((string) $event['title']) ?></strong>
                                <span class="muted"> · <?= e((string) $event['company_name']) ?></span>
                                <p class="sv-timeline-move">
                                    <?= e(ucwords(str_replace('_', ' ', (string) ($event['old_status'] ?? 'submitted')))) ?>
                                    <span aria-hidden="true">→</span>
                                    <?= e(ucwords(str_replace('_', ' ', (string) $event['new_status']))) ?>
                                </p>
                                <?php if (!empty($event['note'])): ?>
                                    <p class="muted"><?= e((string) $event['note']) ?></p>
                                <?php endif; ?>
                                <time datetime="<?= e((string) $event['created_at']) ?>"><?= e(date('d M Y, H:i', strtotime((string) $event['created_at']))) ?></time>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </section>
    <?php endif; ?>
</main>
<?php elseif ($page === 'notifications' && $user && $user['role'] === 'student'): ?>
<main id="main-content" class="dashboard container">
    <div class="dash-top">
        <div>
            <p class="eyebrow">STUDENT WORKSPACE</p>
            <h1>Your inbox.</h1>
            <p class="muted">Approval notes, application updates, interview calls and placement-cell announcements.</p>
        </div>
        <?php if ((int) $unreadNotifications > 0): ?>
            <form method="post" class="inline-form">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="mark_notifications_read">
                <button class="secondary-btn" type="submit">Mark all read</button>
            </form>
        <?php endif; ?>
    </div>

    <?php if (empty($notifications)): ?>
        <div class="empty-state mt-4">
            <h2>Nothing here yet</h2>
            <p>When the placement cell or a recruiter updates your application, it appears here.</p>
        </div>
    <?php else: ?>
        <ul class="notice-list mt-4">
            <?php foreach ($notifications as $notice): ?>
                <li class="panel notice-item <?= empty($notice['read_at']) ? 'is-unread' : '' ?>">
                    <div class="notice-item-head">
                        <strong><?= e((string) $notice['title']) ?></strong>
                        <time datetime="<?= e((string) $notice['created_at']) ?>"><?= e(date('d M Y, H:i', strtotime((string) $notice['created_at']))) ?></time>
                    </div>
                    <p><?= nl2br(e((string) $notice['body'])) ?></p>
                    <?php if (empty($notice['read_at'])): ?>
                        <span class="status-pill">Unread</span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</main>
<?php elseif ($page === 'forbidden'): ?>
<main id="main-content" class="container dashboard">
    <div class="empty-state">
        <h1>Access restricted</h1>
        <p>This page is only available to student accounts.</p>
        <a class="primary-btn" href="index.php?page=dashboard">Return to dashboard</a>
    </div>
</main>
<?php elseif ($page === 'dashboard' && $user): ?>
<main id="main-content" class="dashboard container">
    <div class="dash-top">
        <div>
            <p class="eyebrow"><?= e(strtoupper($user['role'])) ?> WORKSPACE</p>
            <h1>Good to see you, <?= e($user['name']) ?>.</h1>
            <p class="muted">Your workspace reflects current portal activity.</p>
        </div>
        <span class="status-pill">● Account active</span>
    </div>

    <?php if (!empty($dbError)): ?>
        <div class="empty-state mt-4">
            Dashboard information is temporarily unavailable. Please try again later.
        </div>
    <?php else: ?>
        <div class="metric-grid">
            <?php foreach ($counts ?? [] as $label => $value): ?>
                <article class="metric-card">
                    <p><?= e(ucwords(str_replace('_', ' ', $label))) ?></p>
                    <strong><?= (int) $value ?></strong>
                    <small>Current database total</small>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($user['role'] === 'student'): ?>
        <section class="student-grid mt-4" aria-label="Your placement readiness">
            <article class="panel strength-card">
                <div class="strength-head">
                    <div>
                        <p class="eyebrow">PROFILE STRENGTH</p>
                        <h2><?= (int) ($profileStrength ?? 0) ?>% complete</h2>
                    </div>
                    <span class="status-pill <?= ($profileStrength ?? 0) >= 80 ? '' : 'status-pill--warn' ?>">
                        <?= ($profileStrength ?? 0) >= 80 ? 'Interview ready' : 'Needs work' ?>
                    </span>
                </div>
                <div class="strength-bar" role="img" aria-label="Profile <?= (int) ($profileStrength ?? 0) ?> percent complete">
                    <span style="--fill: <?= max(2, (int) ($profileStrength ?? 0)) ?>%"></span>
                </div>
                <ul class="strength-list">
                    <?php foreach (($profileStrengthParts ?? []) as $label => $done): ?>
                        <li class="<?= $done ? 'is-done' : '' ?>">
                            <span aria-hidden="true"><?= $done ? '✓' : '○' ?></span><?= e($label) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <a class="secondary-btn" href="index.php?page=profile">Complete your profile</a>
            </article>

            <article class="panel resume-card">
                <p class="eyebrow">RESUME</p>
                <h2><?= $resume ? 'Your PDF is live' : 'No resume yet' ?></h2>
                <p class="muted">
                    <?= $resume
                        ? 'Recruiters reviewing your applications can open ' . e((string) $resume['original_name']) . '.'
                        : 'Upload a PDF so recruiters can read the full story behind your profile.' ?>
                </p>
                <div class="resume-actions">
                    <?php if ($resume): ?>
                        <a class="secondary-btn" href="index.php?page=resume&amp;id=<?= (int) $resume['id'] ?>" target="_blank" rel="noopener">View resume</a>
                        <a class="secondary-btn" href="index.php?page=resume&amp;id=<?= (int) $resume['id'] ?>&amp;download=1">Download</a>
                    <?php endif; ?>
                    <a class="primary-btn" href="index.php?page=profile#resume"><?= $resume ? 'Replace PDF' : 'Upload PDF' ?></a>
                </div>
            </article>

            <article class="panel alerts-card">
                <div class="strength-head">
                    <div>
                        <p class="eyebrow">ALERTS</p>
                        <h2><?= (int) ($unreadNotifications ?? 0) > 0 ? (int) $unreadNotifications . ' unread' : 'All caught up' ?></h2>
                    </div>
                    <a class="secondary-btn" href="index.php?page=notifications">Open inbox</a>
                </div>
                <?php if (empty($notifications)): ?>
                    <p class="muted">Status changes, announcements and interviews arrive here.</p>
                <?php else: ?>
                    <ul class="notice-mini">
                        <?php foreach ($notifications as $notice): ?>
                            <li class="<?= empty($notice['read_at']) ? 'is-unread' : '' ?>">
                                <strong><?= e($notice['title']) ?></strong>
                                <small><?= e(date('d M', strtotime((string) $notice['created_at']))) ?></small>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </article>
        </section>
    <?php endif; ?>

    <section class="panel mt-4" aria-labelledby="dashboard-next-steps-title">
        <h2 id="dashboard-next-steps-title">Next steps</h2>
        <?php if ($user['role'] === 'student' && ($upcomingInterviews || $studentOffers)): ?>
            <ul class="sv-next-list">
                <?php foreach ($upcomingInterviews as $interview): ?>
                    <li class="sv-next-item">
                        <div>
                            <strong>Interview · <?= e((string) $interview['title']) ?></strong>
                            <span class="muted"> · <?= e((string) $interview['company_name']) ?></span>
                            <p class="muted">
                                <time datetime="<?= e(date('c', strtotime((string) $interview['scheduled_at']))) ?>"><?= e(date('D d M Y, H:i', strtotime((string) $interview['scheduled_at']))) ?></time>
                                <?php if (!empty($interview['mode'])): ?> · <?= e((string) $interview['mode']) ?><?php endif; ?>
                            </p>
                        </div>
                        <a class="secondary-btn" href="index.php?page=applications">Details</a>
                    </li>
                <?php endforeach; ?>
                <?php foreach ($studentOffers as $offer): ?>
                    <li class="sv-next-item">
                        <div>
                            <strong>Offer · <?= e((string) $offer['title']) ?></strong>
                            <span class="muted"> · <?= e((string) $offer['company_name']) ?></span>
                            <p class="muted">
                                <?= $offer['ctc_lpa'] !== null ? e(number_format((float) $offer['ctc_lpa'], 2)) . ' LPA' : 'CTC not disclosed' ?>
                                · <?= e(ucfirst((string) $offer['status'])) ?>
                            </p>
                        </div>
                        <a class="secondary-btn" href="index.php?page=applications">Details</a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="muted">The portal starts with an empty database. Your workspace will fill as real profiles, jobs, and applications are added.</p>
        <?php endif; ?>
        <?php if ($user['role'] === 'student'): ?>
            <a class="secondary-btn" href="index.php?page=jobs">Browse open opportunities</a>
            <a class="secondary-btn" href="career.php">Open the career studio</a>
        <?php elseif (in_array($user['role'], ['admin', 'tpo'], true)): ?>
            <a class="secondary-btn" href="admin.php">Open Admin / TPO workspace <span aria-hidden="true">↗</span></a>
        <?php elseif ($user['role'] === 'recruiter'): ?>
            <a class="secondary-btn" href="recruiter.php">Open recruiter workspace <span aria-hidden="true">↗</span></a>
        <?php endif; ?>
    </section>
</main>
<?php else: ?>
<main id="main-content" class="container dashboard">
    <div class="empty-state">
        <h1>Page not found</h1>
        <p>The page may have moved, or the address may be incorrect.</p>
        <a class="primary-btn" href="index.php">Return home</a>
    </div>
</main>
<?php endif; ?>
<?php if ($isStudentWorkspace): ?>
    <?php sviet_student_dock($page, (int) $unreadNotifications); ?>
    <script src="assets/js/liquid-glass.js?v=<?= e($appCssVersion) ?>" defer></script>
    <script src="assets/js/student-nav.js?v=<?= e($appCssVersion) ?>" defer></script>
<?php endif; ?>
<script src="assets/js/theme.js?v=<?= e($appCssVersion) ?>" defer></script>
<script src="assets/js/site.js" defer></script>
<script src="assets/js/theme-guard.js?v=<?= e($appCssVersion) ?>" defer></script>
</body>
</html>
