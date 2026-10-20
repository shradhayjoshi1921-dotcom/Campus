    </main>
</div>

<nav class="rec-bottom-nav" aria-label="Recruiter workspace">
    <span class="rec-nav-indicator" aria-hidden="true"></span>
    <a class="rec-nav-link <?= $section === 'overview' ? 'is-active' : '' ?>" href="recruiter.php?section=overview" <?= $section === 'overview' ? 'aria-current="page"' : '' ?>>
        <span class="rec-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1Z"/></svg></span>
        <span class="rec-nav-label">Overview</span>
    </a>
    <a class="rec-nav-link <?= $section === 'company' ? 'is-active' : '' ?>" href="recruiter.php?section=company" <?= $section === 'company' ? 'aria-current="page"' : '' ?>>
        <span class="rec-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 21V5a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v16M8 7h2m4 0h2M8 11h2m4 0h2M8 15h2m4 0h2M2 21h20"/></svg></span>
        <span class="rec-nav-label">Company</span>
    </a>
    <a class="rec-nav-link <?= $section === 'jobs' ? 'is-active' : '' ?>" href="recruiter.php?section=jobs" <?= $section === 'jobs' ? 'aria-current="page"' : '' ?>>
        <span class="rec-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="7" width="18" height="14" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M3 12h18m-11 0v2h4v-2"/></svg></span>
        <span class="rec-nav-label">Jobs</span>
    </a>
    <a class="rec-nav-link <?= $section === 'applicants' ? 'is-active' : '' ?>" href="recruiter.php?section=applicants" <?= $section === 'applicants' ? 'aria-current="page"' : '' ?>>
        <span class="rec-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="4"/><path d="M2.5 21v-1a6.5 6.5 0 0 1 13 0v1M18 8h5m-2.5-2.5v5"/></svg></span>
        <span class="rec-nav-label">Applicants</span>
    </a>
    <a class="rec-nav-link <?= $section === 'interviews' ? 'is-active' : '' ?>" href="recruiter.php?section=interviews" <?= $section === 'interviews' ? 'aria-current="page"' : '' ?>>
        <span class="rec-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="3"/><path d="M16 3v4M8 3v4M3 10h18m4 4 2 2 4-4"/></svg></span>
        <span class="rec-nav-label">Interviews</span>
    </a>
</nav>

<footer class="rec-footer">
    <span>Swami Vivekanand Institute of Engineering and Technology</span>
    <span>SVIET Training &amp; Placement Portal</span>
</footer>
<script src="assets/js/recruiter-nav.js?v=<?= recruiter_h($recruiterNavJsVersion) ?>" defer></script>
</body>
</html>
