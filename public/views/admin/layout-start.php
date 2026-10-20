<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#08131f">
    <meta name="description" content="SVIET Training &amp; Placement control centre: approvals, students, placements, reports and governance.">
    <title><?= admin_h($pageTitle) ?> · SVIET Control Centre</title>
    <link rel="stylesheet" href="assets/css/app.css?v=<?= admin_h($appCssVersion) ?>">
    <link rel="stylesheet" href="assets/css/admin.css?v=<?= admin_h($adminCssVersion) ?>">
</head>
<body class="admin-portal">
<a class="skip-link" href="#admin-main">Skip to main content</a>
<div class="admin-ambient" aria-hidden="true">
    <span class="admin-orb admin-orb--one"></span>
    <span class="admin-orb admin-orb--two"></span>
</div>

<header class="admin-topbar">
    <a class="admin-brand" href="index.php" aria-label="SVIET placement portal home">
        <span class="admin-brand-mark" aria-hidden="true">S</span>
        <span class="admin-brand-copy">
            <strong>SVIET</strong>
            <small>CONTROL CENTRE</small>
        </span>
    </a>
    <div class="admin-topbar-right">
        <span class="admin-role-pill">
            <span class="admin-role-dot" aria-hidden="true"></span>
            <?= admin_h(strtoupper((string) $user['role'])) ?>
        </span>
        <span class="admin-account">
            <span class="admin-avatar" aria-hidden="true"><?= admin_h(strtoupper(mb_substr((string) $user['name'], 0, 1))) ?></span>
            <span class="admin-account-name"><?= admin_h((string) $user['name']) ?></span>
        </span>
        <form method="post" action="index.php" class="admin-signout-form">
            <input type="hidden" name="_csrf" value="<?= admin_h($csrf) ?>">
            <input type="hidden" name="action" value="logout">
            <button class="admin-signout" type="submit">Sign out</button>
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
