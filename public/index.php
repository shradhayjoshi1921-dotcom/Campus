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
                header('Location: index.php?page=dashboard');
                exit();
            }
            $error =
                'We could not sign you in. Check your details or, if you registered as a recruiter, wait for approval.';
        } catch (Throwable $e) {
            error_log('Portal sign-in failure: ' . $e->getMessage());
            $error =
                'Sign-in is temporarily unavailable. Please try again later.';
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
if (in_array($page, ['jobs', 'applications'], true) && !$user) {
    header('Location: index.php?page=login');
    exit();
}
if (
    in_array($page, ['jobs', 'applications'], true) &&
    $user &&
    $user['role'] !== 'student'
) {
    http_response_code(403);
    $page = 'forbidden';
}
if ($page === 'jobs' && $user && $user['role'] === 'student') {
    try {
        $db = Database::connection();
        $q = $db->query(
            "SELECT j.*,c.name AS company_name FROM jobs j JOIN companies c ON c.id=j.company_id WHERE j.status='published' AND c.approval_status='approved' AND (j.closes_at IS NULL OR j.closes_at>=NOW()) ORDER BY j.created_at DESC",
        );
        $jobs = $q->fetchAll();
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
    'forbidden',
];
if (!in_array($page, $knownPages, true)) {
    http_response_code(404);
}
function e(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Prepare shared layout data and a page-specific browser title.
$csrf = Security::csrfToken();
$appCssVersion = (string) (filemtime(__DIR__ . '/assets/css/app.css') ?: 1);
$pageTitle = match ($page) {
    'home' => 'SVIET Placement Portal',
    'login' => 'Sign in | SVIET Placement Portal',
    'register' => 'Create account | SVIET Placement Portal',
    'dashboard' => 'Dashboard | SVIET Placement Portal',
    'profile' => 'Student profile | SVIET Placement Portal',
    'jobs' => 'Opportunities | SVIET Placement Portal',
    'applications' => 'Applications | SVIET Placement Portal',
    'forbidden' => 'Access restricted | SVIET Placement Portal',
    default => 'Page not found | SVIET Placement Portal',
};
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="<?= $page === 'home'
        ? '#f8fafb'
        : '#07111e' ?>">
    <meta name="description" content="A placement portal for Swami Vivekanand Institute of Engineering and Technology. Explore opportunities, manage applications, and connect students, recruiters, and the Training &amp; Placement Cell.">
    <title><?= e($pageTitle) ?></title>
    <link rel="stylesheet" href="assets/css/app.css?v=<?= e($appCssVersion) ?>">
    <?php if ($page === 'home'): ?>
        <link rel="stylesheet" href="assets/css/homepage.css">
    <?php endif; ?>
    <style>
        /* Campus homepage refresh: remove the top dark strip and sharpen the visual hierarchy. */
        html:has(body.homepage) { background: #f6f9fb; }
        body.homepage {
            margin: 0 !important;
            padding-top: 0 !important;
            border-top: 0 !important;
            background:
                radial-gradient(ellipse at 12% 8%, rgba(166, 232, 199, .22), transparent 34rem),
                radial-gradient(ellipse at 92% 18%, rgba(172, 205, 235, .22), transparent 38rem),
                linear-gradient(180deg, #f8fbfd 0%, #f0f5f8 100%);
        }
        body.homepage .site-nav {
            top: 0;
            width: min(100%, 1500px);
            min-height: 76px;
            margin: 0 auto 22px;
            padding-inline: clamp(20px, 5vw, 76px);
            border: 0;
            border-bottom: 1px solid rgba(18, 42, 64, .09);
            border-radius: 0 0 22px 22px;
            background: rgba(250, 253, 255, .94);
            box-shadow: 0 10px 30px rgba(15, 39, 60, .055);
            -webkit-backdrop-filter: blur(18px) saturate(145%);
            backdrop-filter: blur(18px) saturate(145%);
        }
        body.homepage .home-hero {
            width: min(calc(100% - 48px), 1420px);
            min-height: min(740px, calc(100svh - 120px));
            border: 1px solid rgba(255,255,255,.2);
            border-radius: 32px;
            background:
                radial-gradient(ellipse at 82% 10%, rgba(102, 184, 158, .22), transparent 32rem),
                radial-gradient(ellipse at 12% 92%, rgba(87, 135, 181, .18), transparent 34rem),
                linear-gradient(128deg, #071827 0%, #102c40 55%, #123b43 100%);
            box-shadow: 0 28px 80px rgba(12, 35, 52, .17), inset 0 1px rgba(255,255,255,.1);
        }
        body.homepage .home-hero-grid {
            gap: clamp(38px, 6vw, 92px);
            padding-block: clamp(66px, 8.5vh, 104px) 88px;
        }
        body.homepage .home-hero h1 { max-width: 780px; line-height: 1.01; }
        body.homepage .home-hero h1 span {
            background: linear-gradient(100deg, #baf2d4 0%, #8bdcbd 52%, #d6f8e5 100%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        body.homepage .hero-feature-card {
            border-color: rgba(234, 249, 255, .24);
            background: linear-gradient(145deg, rgba(238, 248, 252, .15), rgba(176, 204, 221, .055) 62%, rgba(8, 23, 37, .32));
            box-shadow: 0 28px 72px rgba(0, 7, 17, .25), inset 0 1px rgba(255,255,255,.22);
        }
        body.homepage .feature-section { padding-block: clamp(72px, 8vw, 112px); }
        body.homepage .feature-card {
            min-height: 250px;
            padding: clamp(24px, 2.5vw, 32px);
            transition: transform .24s ease, box-shadow .24s ease, border-color .24s ease;
        }
        body.homepage .feature-card:hover { transform: translateY(-5px); }
        body.homepage .feature-icon { border-radius: 16px; }
        body.homepage .site-footer { max-width: 1420px; }
        @media (max-width: 780px) {
            body.homepage .site-nav { width: 100%; min-height: 68px; margin-bottom: 14px; padding-inline: 18px; border-radius: 0 0 18px 18px; }
            body.homepage .home-hero { width: calc(100% - 28px); min-height: 0; border-radius: 24px; }
            body.homepage .home-hero-grid { padding: 58px 22px 68px; }
        }
        @media (max-width: 520px) {
            body.homepage .site-nav { width: 100%; margin-top: 0; }
            body.homepage .home-hero { width: calc(100% - 18px); border-radius: 20px; }
            body.homepage .home-hero-grid { padding: 50px 18px 58px; }
        }
        @media (prefers-reduced-motion: reduce) {
            body.homepage *, body.homepage *::before, body.homepage *::after { scroll-behavior: auto !important; animation-duration: .01ms !important; transition-duration: .01ms !important; }
        }
    </style>
</head>
<body class="<?= $page === 'home' ? 'homepage' : '' ?>">
<a class="skip-link" href="#main-content">Skip to main content</a>
<header class="site-nav">
    <a class="brand" href="index.php" aria-label="Swami Vivekanand Institute of Engineering and Technology home">
        <span class="brand-copy">
            <strong>SVIET</strong>
            <small>TRAINING &amp; PLACEMENT</small>
        </span>
    </a>
    <button class="nav-toggle" id="nav-toggle" type="button" aria-label="Open navigation" aria-controls="site-navigation" aria-expanded="false">
        <span></span><span></span><span class="sr-only">Toggle navigation</span>
    </button>
    <nav class="nav-links" id="site-navigation" aria-label="Main navigation">
        <a href="index.php#portal-workspaces">Portal</a>
        <a href="index.php#how-it-works">How it works</a>
        <a href="index.php#for-recruiters">For recruiters</a>
        <?php if ($user): ?>
            <a href="index.php?page=dashboard">Dashboard</a>
            <?php if ($user['role'] === 'student'): ?>
                <a href="index.php?page=profile">My profile</a>
                <a href="index.php?page=jobs">Opportunities</a>
                <a href="index.php?page=applications">Applications</a>
                <a href="career.php">Career insights</a>
            <?php elseif ($user['role'] === 'recruiter'): ?>
                <a href="recruiter.php">Recruiter workspace</a>
            <?php elseif (in_array($user['role'], ['admin', 'tpo'], true)): ?>
                <a href="admin.php">TPO / Admin</a>
            <?php endif; ?>
            <form method="post" action="index.php" class="inline-form">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="logout">
                <button class="nav-cta" type="submit">Sign out</button>
            </form>
        <?php else: ?>
            <a href="index.php?page=login">Portal sign in</a>
            <a class="nav-cta" href="index.php?page=register">Create account <span aria-hidden="true">↗</span></a>
        <?php endif; ?>
    </nav>
</header>
<?php if ($error || $success): ?>
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
<main id="main-content">
    <section class="home-hero" id="home" aria-labelledby="home-title">
        <div class="home-hero-bubbles" aria-hidden="true">
            <span class="bubble-orb bubble-orb--one"></span>
            <span class="bubble-orb bubble-orb--two"></span>
        </div>

        <div class="container home-hero-grid">
            <div class="home-hero-copy" data-reveal>
                <p class="eyebrow">
                    <span class="eyebrow-dot" aria-hidden="true"></span>
                    SVIET Training &amp; Placement Portal
                </p>
                <h1 id="home-title">SVIET placements<br><span>in one place.</span></h1>
                <p class="hero-sub">
                    Student profiles, approved opportunities, and application updates—supported by the SVIET Training &amp; Placement Cell.
                </p>

                <div class="hero-actions">
                    <?php if (!$user): ?>
                        <a class="primary-btn" href="index.php?page=login">
                            Sign in to the portal <span class="btn-arrow" aria-hidden="true">↗</span>
                        </a>
                        <a class="secondary-btn" href="index.php?page=register">Create an account</a>
                    <?php elseif ($user['role'] === 'student'): ?>
                        <a class="primary-btn" href="index.php?page=dashboard">
                            Open your dashboard <span class="btn-arrow" aria-hidden="true">↗</span>
                        </a>
                        <a class="secondary-btn" href="index.php?page=jobs">Browse opportunities</a>
                    <?php elseif ($user['role'] === 'recruiter'): ?>
                        <a class="primary-btn" href="recruiter.php">
                            Open recruiter workspace <span class="btn-arrow" aria-hidden="true">↗</span>
                        </a>
                    <?php else: ?>
                        <a class="primary-btn" href="admin.php">
                            Open TPO / Admin workspace <span class="btn-arrow" aria-hidden="true">↗</span>
                        </a>
                    <?php endif; ?>
                </div>

                <div class="hero-audience" role="group" aria-label="Portal access for students, recruiters, and the Training and Placement Cell">
                    <span class="hero-audience-label">Serving the SVIET community</span>
                    <span class="audience-pill"><i aria-hidden="true"></i>Students</span>
                    <span class="audience-pill"><i aria-hidden="true"></i>Recruiters</span>
                    <span class="audience-pill"><i aria-hidden="true"></i>Placement Cell</span>
                </div>
            </div>

            <aside class="hero-feature-card" id="how-it-works" data-reveal aria-label="Student placement workflow">
                <div class="hero-card-head">
                    <span>STUDENT WORKFLOW</span>
                    <span class="hero-card-status">SVIET PLACEMENT PORTAL</span>
                </div>
                <h2>From profile to<br>opportunity.</h2>
                <ol class="hero-flow">
                    <li>
                        <span class="flow-index">01</span>
                        <span class="flow-copy"><strong>Build your profile</strong><small>Add your academic details and skills</small></span>
                    </li>
                    <li>
                        <span class="flow-index">02</span>
                        <span class="flow-copy"><strong>Explore approved opportunities</strong><small>Review roles and eligibility</small></span>
                    </li>
                    <li>
                        <span class="flow-index">03</span>
                        <span class="flow-copy"><strong>Track your applications</strong><small>Follow updates in your workspace</small></span>
                    </li>
                </ol>
                <div class="hero-card-foot">
                    <span aria-hidden="true"></span>
                    <span>A dedicated placement portal for the SVIET community.</span>
                </div>
            </aside>
        </div>

        <a class="hero-scroll-cue" href="#portal-workspaces">
            <span>EXPLORE THE PORTAL</span><span aria-hidden="true"></span>
        </a>
    </section>

    <section class="feature-section" id="portal-workspaces" aria-labelledby="portal-workspaces-title">
        <div class="container">
            <div class="section-heading" data-reveal>
                <div>
                    <p class="eyebrow">One institute. Connected journeys.</p>
                    <h2 id="portal-workspaces-title">A dedicated portal for <span>every role.</span></h2>
                </div>
                <p>One SVIET-supported workspace for students, recruiting organizations, and authorized Training &amp; Placement staff.</p>
            </div>

            <div class="feature-grid">
                <article class="feature-card" data-reveal>
                    <span class="feature-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="m3 9 9-5 9 5-9 5-9-5Z"/><path d="M7 11.2v5.1c3.2 2.2 6.8 2.2 10 0v-5.1M21 9v5"/></svg>
                    </span>
                    <h3>Student placement portal</h3>
                    <p>Maintain your profile, review approved opportunities, apply for eligible roles, and follow application progress.</p>
                    <?php if ($user && $user['role'] === 'student'): ?>
                        <a class="feature-link" href="index.php?page=jobs">Browse opportunities <span aria-hidden="true">↗</span></a>
                    <?php else: ?>
                        <a class="feature-link" href="index.php?page=login">Student sign in <span aria-hidden="true">↗</span></a>
                    <?php endif; ?>
                </article>

                <article class="feature-card" id="for-recruiters" data-reveal>
                    <span class="feature-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><rect x="3" y="7" width="18" height="14" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M3 12h18m-11 0v2h4v-2"/></svg>
                    </span>
                    <h3>Recruiter access</h3>
                    <p>Request recruiter access, then submit a company profile for Training &amp; Placement review before managing opportunities and applicants.</p>
                    <?php if ($user && $user['role'] === 'recruiter'): ?>
                        <a class="feature-link" href="recruiter.php">Open recruiter workspace <span aria-hidden="true">↗</span></a>
                    <?php else: ?>
                        <a class="feature-link" href="index.php?page=register&amp;role=recruiter">Request recruiter access <span aria-hidden="true">↗</span></a>
                    <?php endif; ?>
                </article>

                <article class="feature-card" data-reveal>
                    <span class="feature-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M8 3h7l4 4v14H5V6a3 3 0 0 1 3-3Z"/><path d="M15 3v5h5M8 13h8M8 17h5"/></svg>
                    </span>
                    <h3>Training &amp; Placement Cell</h3>
                    <p>Authorized staff can review recruiter access, moderate opportunities, and monitor placement activity.</p>
                    <?php if (
                        $user &&
                        in_array($user['role'], ['admin', 'tpo'], true)
                    ): ?>
                        <a class="feature-link" href="admin.php">Open TPO workspace <span aria-hidden="true">↗</span></a>
                    <?php else: ?>
                        <a class="feature-link" href="index.php?page=login">Authorized staff sign in <span aria-hidden="true">↗</span></a>
                    <?php endif; ?>
                </article>
            </div>
        </div>
    </section>

    <footer class="site-footer container">
        <strong>Swami Vivekanand Institute of Engineering and Technology</strong>
        <span>SVIET Training &amp; Placement Portal · <?= date('Y') ?></span>
    </footer>
</main>
<?php elseif ($page === 'login' || $page === 'register'): ?>
<main id="main-content" class="auth-wrap">
    <section class="auth-card" aria-labelledby="auth-title">
        <p class="eyebrow">SVIET Training &amp; Placement Portal</p>
        <h1 id="auth-title">
            <?= $page === 'login'
                ? 'Sign in to your portal.'
                : 'Create your portal account.' ?>
        </h1>
        <p class="muted">
            <?= $page === 'login'
                ? 'Use your SVIET placement portal credentials to continue.'
                : 'Choose the account type that matches your placement role.' ?>
        </p>

        <form method="post" class="auth-form">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="<?= $page === 'login'
                ? 'login'
                : 'register' ?>">

            <?php if ($page === 'register'): ?>
                <label for="account-name">
                    Full name
                    <input id="account-name" class="form-control" name="name" required maxlength="120" autocomplete="name">
                </label>
            <?php endif; ?>

            <label for="account-email">
                Email address
                <input id="account-email" class="form-control" type="email" name="email" required maxlength="190" autocomplete="email">
            </label>

            <label for="account-password">
                Password
                <input id="account-password" class="form-control" type="password" name="password" required minlength="<?= $page ===
                'register'
                    ? '10'
                    : '1' ?>" autocomplete="<?= $page === 'login'
    ? 'current-password'
    : 'new-password' ?>">
                <?php if ($page === 'register'): ?>
                    <small>Use at least 10 characters.</small>
                <?php endif; ?>
            </label>

            <?php if ($page === 'register'): ?>
                <label for="account-role">
                    Account type
                    <select id="account-role" class="form-select" name="role" required aria-describedby="account-role-help">
                        <option value="student" <?= $selectedRole === 'student'
                            ? 'selected'
                            : '' ?>>Student</option>
                        <option value="recruiter" <?= $selectedRole ===
                        'recruiter'
                            ? 'selected'
                            : '' ?>>Recruiter / Company</option>
                    </select>
                    <small id="account-role-help">Recruiter accounts require Training &amp; Placement Cell approval before sign-in. After approval, submit your company profile for review.</small>
                </label>
            <?php endif; ?>

            <button class="primary-btn w-100" type="submit">
                <?= $page === 'login'
                    ? 'Sign in'
                    : 'Create account' ?> <span aria-hidden="true">↗</span>
            </button>
        </form>

        <p class="auth-switch">
            <?= $page === 'login'
                ? 'New to the portal?'
                : 'Already registered?' ?>
            <a href="index.php?page=<?= $page === 'login'
                ? 'register'
                : 'login' ?>">
                <?= $page === 'login' ? 'Create an account' : 'Sign in' ?>
            </a>
        </p>
        <a class="auth-back-link" href="index.php">Back to the SVIET portal</a>
    </section>
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

    <section class="panel mt-4" aria-labelledby="dashboard-next-steps-title">
        <h2 id="dashboard-next-steps-title">Next steps</h2>
        <p class="muted">The portal starts with an empty database. Your workspace will fill as real profiles, jobs, and applications are added.</p>
        <?php if ($user['role'] === 'student'): ?>
            <a class="secondary-btn" href="index.php?page=jobs">Browse open opportunities</a>
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
<script src="assets/js/site.js" defer></script>
</body>
</html>
