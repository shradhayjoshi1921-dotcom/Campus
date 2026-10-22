<?php
/**
 * Shared shell for the student career studio pages.
 *
 * The caller sets, before requiring this file:
 *   $studioTitle  string  browser title
 *   $studioEyebrow string  small caps label
 *   $studioHeading string  page heading
 *   $studioLead   string  one-sentence description
 *   $studioActive string  career | resume | practice
 *   $studioUnread int     unread notification count for the dock badge
 *
 * The shell never invents page content: it renders the chrome, the studio
 * sub-navigation and the same six-tab dock as the rest of the student
 * workspace, then ends in studio-end.php.
 */
$escape = static fn (?string $value): string => App\Core\Security::escape($value);
$studioNav = [
    'career' => ['label' => 'Career fit', 'href' => 'career.php', 'note' => 'Fit, gaps, roadmap'],
    'resume' => ['label' => 'Resume review', 'href' => 'resume-review.php', 'note' => 'Extract and compare'],
    'practice' => ['label' => 'Practice', 'href' => 'practice.php', 'note' => 'Mock interview and tests'],
];
$appCssVersion = asset_version();
$csrfToken = App\Core\Security::csrfToken();
$user = App\Core\Security::currentUser();
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#fff7f0">
    <meta name="description" content="Career intelligence for SVIET students: explainable career fit, skill gaps, learning roadmaps, resume review and practice.">
    <title><?= $escape($studioTitle ?? 'Career studio') ?> · SVIET</title>
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
    <link rel="stylesheet" href="assets/css/theme.css?v=<?= $escape($appCssVersion) ?>">
    <link rel="stylesheet" href="assets/css/app.css?v=<?= $escape($appCssVersion) ?>">
    <link rel="stylesheet" href="assets/css/intelligence.css?v=<?= $escape($appCssVersion) ?>">
    <link rel="stylesheet" href="assets/css/water-dock.css?v=<?= $escape($appCssVersion) ?>">
    <link rel="stylesheet" href="assets/css/liquid-glass.css?v=<?= $escape($appCssVersion) ?>">
</head>
<body class="studio-portal">
<?php require __DIR__ . '/glass-defs.php'; ?>
<a class="skip-link" href="#main-content">Skip to main content</a>
<header class="bubble-top bubble-top--portal" aria-label="SVIET career studio">
    <a class="bubble bubble--brand" href="index.php?page=dashboard" aria-label="SVIET placement portal">
        <span class="bubble-brand-mark" aria-hidden="true">S</span>
        <span class="bubble-brand-copy">
            <strong>SVIET</strong>
            <small>Career studio</small>
        </span>
    </a>
    <div class="bubble-cluster">
        <button class="bubble bubble--icon" type="button" data-theme-toggle aria-pressed="false" aria-label="Switch appearance">
            <span class="theme-toggle__track" aria-hidden="true">
                <svg class="theme-toggle__icon theme-toggle__icon--sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><circle cx="12" cy="12" r="4.2"/><path d="M12 2.5v2.2M12 19.3v2.2M4.2 4.2l1.6 1.6M18.2 18.2l1.6 1.6M2.5 12h2.2M19.3 12h2.2M4.2 19.8l1.6-1.6M18.2 5.8l1.6-1.6"/></svg>
                <svg class="theme-toggle__icon theme-toggle__icon--moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M20 14.4A8.2 8.2 0 0 1 9.6 4a8.4 8.4 0 1 0 10.4 10.4Z"/></svg>
            </span>
        </button>
        <a class="bubble bubble--cta" href="index.php?page=jobs">Open roles</a>
        <?php if ($user): ?>
            <span class="bubble bubble--account" title="<?= $escape((string) $user['name']) ?>" aria-label="Signed in as <?= $escape((string) $user['name']) ?>">
                <?= $escape(strtoupper(mb_substr((string) $user['name'], 0, 1))) ?>
            </span>
        <?php endif; ?>
        <form method="post" action="index.php" class="bubble-form">
            <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
            <input type="hidden" name="action" value="logout">
            <button class="bubble bubble--icon" type="submit" aria-label="Sign out" title="Sign out">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 8l4 4-4 4M14 12H3"/></svg>
            </button>
        </form>
    </div>
</header>
<div class="studio-subnav" role="navigation" aria-label="Career studio sections">
    <div class="studio-subnav__inner">
        <?php foreach ($studioNav as $navKey => $navTab): ?>
            <a class="studio-tab <?= ($studioActive ?? '') === $navKey ? 'is-active' : '' ?>"
               href="<?= $escape($navTab['href']) ?>"
               <?= ($studioActive ?? '') === $navKey ? 'aria-current="page"' : '' ?>>
                <strong><?= $escape($navTab['label']) ?></strong>
                <small><?= $escape($navTab['note']) ?></small>
            </a>
        <?php endforeach; ?>
    </div>
</div>
<main id="main-content" class="dashboard container studio-main">
    <div class="career-head">
        <div>
            <p class="eyebrow"><?= $escape($studioEyebrow ?? 'CAREER STUDIO') ?></p>
            <h1><?= $escape($studioHeading ?? 'Career studio') ?></h1>
            <p class="muted"><?= $escape($studioLead ?? '') ?></p>
        </div>
        <a class="secondary-btn" href="index.php?page=profile">Update profile ↗</a>
    </div>
    <?php foreach (($studioFlash ?? []) as $flash): ?>
        <div class="intel-flash intel-flash--<?= $escape($flash['kind'] ?? 'info') ?>" role="<?= ($flash['kind'] ?? '') === 'error' ? 'alert' : 'status' ?>">
            <?= $escape($flash['text'] ?? '') ?>
        </div>
    <?php endforeach; ?>
