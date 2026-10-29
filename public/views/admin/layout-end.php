        </div>
        <p class="sr-only" id="admin-nav-status" aria-live="polite" aria-atomic="true"></p>
    </main>
</div>

<nav class="admin-dock" data-glass-dock aria-label="Admin workspace">
    <span class="lg-indicator" aria-hidden="true">
        <span class="lg-smoke"></span>
        <span class="lg-specular"></span>
        <span class="lg-rim"></span>
        <span class="lg-caustic"></span>
        <span class="lg-glint"></span>
    </span>
    <div class="admin-dock-track" id="admin-dock-track">
        <?php
        $adminTabs = [
            'overview' => ['label' => 'Overview', 'icon' => '<path d="M4 4h7v7H4zm9 0h7v4h-7zM4 13h7v7H4zm9-3h7v12h-7z"/>'],
            'approvals' => ['label' => 'Approvals', 'icon' => '<path d="M9 11.5 11.5 14 16 9"/><path d="M12 3.5 5 6v5.2c0 4.1 2.8 7.6 7 9.3 4.2-1.7 7-5.2 7-9.3V6z"/>'],
            'students' => ['label' => 'Students', 'icon' => '<circle cx="9" cy="8" r="3.6"/><path d="M2.8 20.5v-.8a6.2 6.2 0 0 1 12.4 0v.8M17 7.4a3.1 3.1 0 0 1 0 5.9M18.4 20.5v-.7a5.6 5.6 0 0 0-1.6-3.9"/>'],
            'placements' => ['label' => 'Placements', 'icon' => '<path d="M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0z"/><path d="M17 5h2a3 3 0 0 1-3 3M7 5H5a3 3 0 0 0 3 3"/>'],
            'jobs' => ['label' => 'Jobs', 'icon' => '<rect x="3" y="7" width="18" height="14" rx="2"/><path d="M8.5 7V5.2A1.7 1.7 0 0 1 10.2 3.5h3.6a1.7 1.7 0 0 1 1.7 1.7V7M3 12.5h18"/>'],
            'reports' => ['label' => 'Reports', 'icon' => '<path d="M4 20V9M10 20V4M16 20v-7M22 20H2"/>'],
            'comms' => ['label' => 'Comms', 'icon' => '<path d="M4 10v4a1 1 0 0 0 1 1h2l6 4V5L7 9H5a1 1 0 0 0-1 1z"/><path d="M17.5 8.5a5 5 0 0 1 0 7"/>'],
            'governance' => ['label' => 'Governance', 'icon' => '<path d="M12 3 4.5 6v5.5c0 4.6 3.1 8.4 7.5 9.5 4.4-1.1 7.5-4.9 7.5-9.5V6z"/><path d="M12 8v4.5M12 16h.01"/>'],
            'intelligence' => ['label' => 'Insights', 'icon' => '<path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M18.4 5.6l-2.1 2.1M7.7 16.3l-2.1 2.1"/><circle cx="12" cy="12" r="3.2"/>'],
        ];
        ?>
        <?php foreach ($adminTabs as $tabSection => $tab): ?>
            <a class="admin-dock-link <?= $section === $tabSection ? 'is-active' : '' ?>"
               data-admin-section="<?= admin_h($tabSection) ?>"
               href="admin.php?section=<?= admin_h($tabSection) ?>"
               <?= $section === $tabSection ? 'aria-current="page"' : '' ?>>
                <span class="admin-dock-icon" aria-hidden="true">
                    <svg width="20" height="20" viewBox="0 0 24 24"><?= $tab['icon'] ?></svg>
                </span>
                <span class="admin-dock-label"><?= admin_h($tab['label']) ?></span>
                <?php if ($tabSection === 'approvals' && $pendingApprovals > 0): ?>
                    <span class="admin-dock-badge" aria-label="<?= (int) $pendingApprovals ?> awaiting review"><?= (int) min(99, $pendingApprovals) ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
</nav>

<footer class="admin-footer">
    <span>Swami Vivekanand Institute of Engineering and Technology</span>
    <span>All state-changing actions are CSRF-protected and audited</span>
</footer>
<script src="assets/js/theme.js?v=<?= admin_h($adminNavJsVersion) ?>" defer></script>
<script src="assets/js/liquid-glass.js?v=<?= admin_h($adminNavJsVersion) ?>" defer></script>
<script src="assets/js/admin-nav.js?v=<?= admin_h($adminNavJsVersion) ?>" defer></script>
<script src="assets/js/theme-guard.js?v=<?= admin_h($adminNavJsVersion) ?>" defer></script>
</body>
</html>
