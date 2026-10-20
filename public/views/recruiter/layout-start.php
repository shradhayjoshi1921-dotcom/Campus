<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#fff8f1">
    <script>
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

    <meta name="description" content="Manage your SVIET recruiter profile, job opportunities, applicants, and interviews.">
    <title><?= recruiter_h($pageTitle) ?> · SVIET Recruiter</title>
    <link rel="stylesheet" href="assets/css/theme.css?v=<?= recruiter_h($appCssVersion) ?>">
    <link rel="stylesheet" href="assets/css/app.css?v=<?= recruiter_h($appCssVersion) ?>">
    <link rel="stylesheet" href="assets/css/recruiter.css?v=<?= recruiter_h($recruiterCssVersion) ?>">
</head>
<body class="recruiter-portal">
<a class="skip-link" href="#main-content">Skip to main content</a>
<div class="rec-ambient" aria-hidden="true">
    <span class="rec-orb rec-orb--one"></span>
    <span class="rec-orb rec-orb--two"></span>
    <span class="rec-orb rec-orb--three"></span>
</div>

<header class="rec-topbar">
    <a class="rec-brand" href="index.php" aria-label="SVIET placement portal home">
        <span class="rec-brand-mark" aria-hidden="true">S</span>
        <span class="rec-brand-copy">
            <strong>SVIET</strong>
            <small>RECRUITER WORKSPACE</small>
        </span>
    </a>

    <div class="rec-topbar-right">
        <button class="theme-toggle" type="button" data-theme-toggle aria-pressed="false" aria-label="Switch appearance">
            <span class="theme-toggle__track" aria-hidden="true">
                <svg class="theme-toggle__icon theme-toggle__icon--sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><circle cx="12" cy="12" r="4.2"/><path d="M12 2.5v2.2M12 19.3v2.2M4.2 4.2l1.6 1.6M18.2 18.2l1.6 1.6M2.5 12h2.2M19.3 12h2.2M4.2 19.8l1.6-1.6M18.2 5.8l1.6-1.6"/></svg>
                <svg class="theme-toggle__icon theme-toggle__icon--moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M20 14.4A8.2 8.2 0 0 1 9.6 4a8.4 8.4 0 1 0 10.4 10.4Z"/></svg>
            </span>
            <span class="theme-toggle__label">Light orange</span>
        </button>
        <span class="rec-company-state rec-company-state--<?= recruiter_h(str_replace('_', '-', $companyStatus)) ?>">
            <span class="rec-status-dot" aria-hidden="true"></span>
            <?= recruiter_h($companyStatusLabel) ?>
        </span>
        <div class="rec-account">
            <span class="rec-avatar" aria-hidden="true"><?= recruiter_h(strtoupper(mb_substr((string) $user['name'], 0, 1))) ?></span>
            <span class="rec-account-name"><?= recruiter_h((string) $user['name']) ?></span>
        </div>
        <form method="post" action="index.php" class="rec-signout-form">
            <input type="hidden" name="_csrf" value="<?= recruiter_h($csrf) ?>">
            <input type="hidden" name="action" value="logout">
            <button class="rec-signout" type="submit">Sign out</button>
        </form>
    </div>
</header>

<div class="rec-workspace">
    <main id="main-content" class="rec-main">
        <div class="rec-page-content" id="recruiter-section-content">
        <div class="rec-page-heading">
            <div>
                <p class="rec-eyebrow">SVIET · PLACEMENT PORTAL</p>
                <h1><?= recruiter_h($pageTitle) ?></h1>
                <p class="rec-page-description"><?= recruiter_h($pageDescription) ?></p>
            </div>
            <?php if ($section === 'jobs' && $companyStatus === 'approved'): ?>
                <a class="rec-button rec-button--primary rec-heading-action" href="recruiter.php?section=jobs&mode=create">
                    <span aria-hidden="true">＋</span> Create a role
                </a>
            <?php elseif ($section === 'overview' && $companyId > 0 && $companyStatus === 'approved'): ?>
                <a class="rec-button rec-button--primary rec-heading-action" href="recruiter.php?section=jobs&mode=create">
                    <span aria-hidden="true">＋</span> Create a role
                </a>
            <?php endif; ?>
        </div>

        <?php if ($companyLoadError): ?>
            <div class="rec-alert rec-alert--error" role="alert">
                We couldn't load some workspace information. Please refresh or try again shortly.
            </div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="rec-alert rec-alert--error" role="alert" aria-live="assertive"><?= recruiter_h($error) ?></div>
        <?php elseif ($message !== ''): ?>
            <div class="rec-alert rec-alert--success" role="status" aria-live="polite"><?= recruiter_h($message) ?></div>
        <?php endif; ?>
