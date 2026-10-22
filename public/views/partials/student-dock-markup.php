<?php
/**
 * The student dock, in one place.
 *
 * Required from `sviet_student_dock()` in index.php and from the career studio
 * shell, so the six destinations, their order and their markup cannot drift
 * apart. The caller sets `$active` (a tab key) and `$unread` (an integer).
 */
$tabs = [
    'dashboard' => ['label' => 'Home', 'href' => 'index.php?page=dashboard', 'icon' => '<path d="M4 11.2 12 4l8 7.2V20a1 1 0 0 1-1 1h-4.6v-5.6H9.6V21H5a1 1 0 0 1-1-1z"/>'],
    'jobs' => ['label' => 'Jobs', 'href' => 'index.php?page=jobs', 'icon' => '<rect x="3" y="7" width="18" height="14" rx="2"/><path d="M8.5 7V5.2A1.7 1.7 0 0 1 10.2 3.5h3.6a1.7 1.7 0 0 1 1.7 1.7V7M3 12.5h18"/>'],
    'career' => ['label' => 'Career', 'href' => 'career.php', 'icon' => '<path d="M4 19V9M10 19V4M16 19v-7M22 19H2"/>'],
    'applications' => ['label' => 'Applications', 'href' => 'index.php?page=applications', 'icon' => '<path d="M7 3h7l4 4v14H7z"/><path d="M14 3v5h4M10 12.5h5M10 16.5h4"/>'],
    'notifications' => ['label' => 'Alerts', 'href' => 'index.php?page=notifications', 'icon' => '<path d="M6 9a6 6 0 1 1 12 0c0 4 1.6 5.6 1.6 5.6H4.4S6 13 6 9Z"/><path d="M10 18.5a2.2 2.2 0 0 0 4 0"/>'],
    'profile' => ['label' => 'Profile', 'href' => 'index.php?page=profile', 'icon' => '<circle cx="12" cy="8.2" r="3.7"/><path d="M4.8 20.6a7.2 7.2 0 0 1 14.4 0"/>'],
];
$active = $active ?? '';
$unread = (int) ($unread ?? 0);
?>
    <nav class="sv-dock" data-glass-dock aria-label="Student workspace">
        <span class="lg-indicator" aria-hidden="true">
            <span class="lg-smoke"></span>
            <span class="lg-specular"></span>
            <span class="lg-rim"></span>
            <span class="lg-caustic"></span>
            <span class="lg-glint"></span>
        </span>
        <div class="sv-dock-track" id="sv-dock-track">
            <?php foreach ($tabs as $dockKey => $dockTab): ?>
                <a class="sv-dock-link <?= $active === $dockKey ? 'is-active' : '' ?>"
                   data-sv-section="<?= htmlspecialchars($dockKey, ENT_QUOTES) ?>"
                   href="<?= htmlspecialchars($dockTab['href'], ENT_QUOTES) ?>"
                   <?= $active === $dockKey ? 'aria-current="page"' : '' ?>>
                    <span class="sv-dock-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><?= $dockTab['icon'] ?></svg>
                    </span>
                    <span class="sv-dock-label"><?= htmlspecialchars($dockTab['label'], ENT_QUOTES) ?></span>
                    <?php if ($dockKey === 'notifications' && $unread > 0): ?>
                        <span class="sv-dock-badge" aria-label="<?= $unread ?> unread notifications"><?= $unread > 99 ? '99+' : $unread ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </nav>
