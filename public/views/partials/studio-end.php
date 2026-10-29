<?php
/** Closes the career studio shell opened by studio-start.php. */
$escape = static fn (?string $value): string => App\Core\Security::escape($value);
$appCssVersion = asset_version();
?>
</main>
<footer class="site-footer studio-footer">
    <span>Swami Vivekanand Institute of Engineering and Technology</span>
    <span>Estimates only — eligibility is decided by each employer's published criteria.</span>
</footer>
<?php
$active = 'career';
$unread = (int) ($studioUnread ?? 0);
require __DIR__ . '/student-dock-markup.php';
?>
<script src="assets/js/theme.js?v=<?= $escape($appCssVersion) ?>" defer></script>
<script src="assets/js/site.js" defer></script>
<script src="assets/js/liquid-glass.js?v=<?= $escape($appCssVersion) ?>" defer></script>
<script src="assets/js/student-nav.js?v=<?= $escape($appCssVersion) ?>" defer></script>
<script src="assets/js/theme-guard.js?v=<?= $escape($appCssVersion) ?>" defer></script>
</body>
</html>
