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

    <meta name="description" content="SVIET Training &amp; Placement control centre: approvals, students, placements, reports and governance.">
    <title><?= admin_h($pageTitle) ?> · SVIET Control Centre</title>
    <link rel="stylesheet" href="assets/css/theme.css?v=<?= admin_h($appCssVersion) ?>">
    <link rel="stylesheet" href="assets/css/app.css?v=<?= admin_h($appCssVersion) ?>">
    <link rel="stylesheet" href="assets/css/admin.css?v=<?= admin_h($adminCssVersion) ?>">
    <link rel="stylesheet" href="assets/css/liquid-glass.css?v=<?= admin_h($adminCssVersion) ?>">
</head>
<body class="admin-portal">
<a class="skip-link" href="#admin-main">Skip to main content</a>
<div class="admin-ambient" aria-hidden="true">
    <span class="admin-orb admin-orb--one"></span>
    <span class="admin-orb admin-orb--two"></span>
</div>

<header class="bubble-top bubble-top--portal" aria-label="Training and Placement control centre">
    <a class="bubble bubble--brand" href="index.php" aria-label="SVIET placement portal home">
        <span class="bubble-brand-mark" aria-hidden="true">S</span>
        <span class="bubble-brand-copy">
            <strong>SVIET</strong>
            <small>Control centre</small>
        </span>
    </a>
    <div class="bubble-cluster">
        <button class="bubble bubble--icon" type="button" data-theme-toggle aria-pressed="false" aria-label="Switch appearance">
            <span class="theme-toggle__track" aria-hidden="true">
                <svg class="theme-toggle__icon theme-toggle__icon--sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><circle cx="12" cy="12" r="4.2"/><path d="M12 2.5v2.2M12 19.3v2.2M4.2 4.2l1.6 1.6M18.2 18.2l1.6 1.6M2.5 12h2.2M19.3 12h2.2M4.2 19.8l1.6-1.6M18.2 5.8l1.6-1.6"/></svg>
                <svg class="theme-toggle__icon theme-toggle__icon--moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M20 14.4A8.2 8.2 0 0 1 9.6 4a8.4 8.4 0 1 0 10.4 10.4Z"/></svg>
            </span>
        </button>
        <span class="bubble bubble--state">
            <span class="bubble-dot" aria-hidden="true"></span>
            <?= admin_h(strtoupper((string) $user['role'])) ?>
        </span>
        <span class="bubble bubble--account" title="<?= admin_h((string) $user['name']) ?>" aria-label="Signed in as <?= admin_h((string) $user['name']) ?>">
            <?= admin_h(strtoupper(mb_substr((string) $user['name'], 0, 1))) ?>
        </span>
        <form method="post" action="index.php" class="bubble-form">
            <input type="hidden" name="_csrf" value="<?= admin_h($csrf) ?>">
            <input type="hidden" name="action" value="logout">
            <button class="bubble bubble--icon" type="submit" aria-label="Sign out" title="Sign out">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 8l4 4-4 4M14 12H3"/></svg>
            </button>
        </form>
    </div>
</header>

<div class="admin-workspace">
    <main id="admin-main" class="admin-main">
        <div class="admin-page-content" id="admin-section-content">
            <div class="admin-page-heading">
                <div>
                    <p class="admin-eyebrow">TRAINING &amp; PLACEMENT · SVIET</p>
                    <h1><?= admin_h($pageTitle) ?></h1>
                    <p class="admin-page-description"><?= admin_h($pageDescription) ?></p>
                </div>
                <?php if ($section === 'approvals' && $pendingApprovals > 0): ?>
                    <span class="admin-counter">
                        <strong><?= (int) $pendingApprovals ?></strong>
                        awaiting review
                    </span>
                <?php elseif ($section === 'placements' && $offersReady): ?>
                    <a class="admin-button admin-button--ghost" href="admin.php?section=reports&export=offers">Export offers CSV</a>
                <?php elseif ($section === 'reports'): ?>
                    <div class="admin-heading-actions">
                        <a class="admin-button admin-button--ghost" href="admin.php?section=reports&export=branch">Branch CSV</a>
                        <a class="admin-button admin-button--ghost" href="admin.php?section=reports&export=students">Students CSV</a>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($error !== ''): ?>
                <div class="admin-alert admin-alert--error" role="alert" aria-live="assertive"><?= admin_h($error) ?></div>
            <?php elseif ($message !== ''): ?>
                <div class="admin-alert admin-alert--success" role="status" aria-live="polite"><?= admin_h($message) ?></div>
            <?php endif; ?>
            <?php if ($sectionError !== ''): ?>
                <div class="admin-alert admin-alert--error" role="alert"><?= admin_h($sectionError) ?></div>
            <?php endif; ?>
            <?php if (!$offersReady && in_array($section, ['placements', 'reports'], true)): ?>
                <div class="admin-alert admin-alert--warn" role="status">
                    Offer tracking needs one migration. Run <code>database/migrations/2026-10-20-admin-portal-offers.sql</code>
                    in phpMyAdmin, then refresh. Everything else keeps working meanwhile.
                </div>
            <?php endif; ?>
