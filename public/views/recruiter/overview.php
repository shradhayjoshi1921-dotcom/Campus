<section class="rec-stat-grid" aria-label="Recruiter overview metrics">
    <article class="rec-stat-card" data-reveal>
        <span class="rec-stat-icon rec-stat-icon--mint" aria-hidden="true">
            <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="17" rx="3"/><path d="M8 2v4m8-4v4M3 9h18M8 13h3m-3 4h7"/></svg>
        </span>
        <p>Live roles</p>
        <strong><?= (int) $statistics['live_jobs'] ?></strong>
        <a href="recruiter.php?section=jobs&status=published">View published roles <span aria-hidden="true">↗</span></a>
    </article>
    <article class="rec-stat-card" data-reveal>
        <span class="rec-stat-icon rec-stat-icon--blue" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="M16 20v-1.5a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4V20"/><circle cx="9.5" cy="7" r="4"/><path d="M17 11a4 4 0 1 0-1.1-7.8M21 20v-1.5a4 4 0 0 0-3-3.9"/></svg>
        </span>
        <p>New applicants</p>
        <strong><?= (int) $statistics['new_applicants'] ?></strong>
        <a href="recruiter.php?section=applicants&status=submitted">Review new applicants <span aria-hidden="true">↗</span></a>
    </article>
    <article class="rec-stat-card" data-reveal>
        <span class="rec-stat-icon rec-stat-icon--violet" aria-hidden="true">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/></svg>
        </span>
        <p>Active pipeline</p>
        <strong><?= (int) $statistics['active_pipeline'] ?></strong>
        <a href="recruiter.php?section=applicants">Open candidate pipeline <span aria-hidden="true">↗</span></a>
    </article>
    <article class="rec-stat-card" data-reveal>
        <span class="rec-stat-icon rec-stat-icon--amber" aria-hidden="true">
            <svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="3"/><path d="M16 3v4M8 3v4M3 10h18M8 14h3m2 0h3m-8 4h3"/></svg>
        </span>
        <p>Upcoming interviews</p>
        <strong><?= (int) $statistics['upcoming_interviews'] ?></strong>
        <a href="recruiter.php?section=interviews">Open interview schedule <span aria-hidden="true">↗</span></a>
    </article>
</section>

<div class="rec-overview-grid">
    <section class="rec-card rec-attention-card" aria-labelledby="rec-next-step-title" data-reveal>
        <div class="rec-section-heading">
            <div>
                <p class="rec-eyebrow">YOUR NEXT STEP</p>
                <h2 id="rec-next-step-title">
                    <?php if (!$companyId): ?>Set up your company profile<?php elseif ($companyStatus === 'pending'): ?>Company review in progress<?php elseif ($companyStatus === 'rejected'): ?>Update your company details<?php else: ?>Your workspace is ready<?php endif; ?>
                </h2>
            </div>
            <span class="rec-company-state rec-company-state--<?= recruiter_h(str_replace('_', '-', $companyStatus)) ?>">
                <span class="rec-status-dot" aria-hidden="true"></span><?= recruiter_h($companyStatusLabel) ?>
            </span>
        </div>

        <?php if (!$companyId): ?>
            <p>Submit your organization details to the SVIET Training &amp; Placement Cell. Once approved, you can publish roles directly to eligible students.</p>
            <a class="rec-button rec-button--primary" href="recruiter.php?section=company">Complete company profile <span aria-hidden="true">↗</span></a>
        <?php elseif ($companyStatus === 'pending'): ?>
            <p>Your company profile is with the Training &amp; Placement Cell for review. You can prepare draft roles while you wait; publishing unlocks after approval.</p>
            <a class="rec-button rec-button--secondary" href="recruiter.php?section=company">Review submitted profile</a>
        <?php elseif ($companyStatus === 'rejected'): ?>
            <p>The Training &amp; Placement Cell has requested changes. Update the company profile and resubmit it before publishing roles.</p>
            <a class="rec-button rec-button--primary" href="recruiter.php?section=company">Update company profile <span aria-hidden="true">↗</span></a>
        <?php else: ?>
            <p>Your company is approved. You can publish roles directly; the Training &amp; Placement Cell may pause or close listings when needed.</p>
            <div class="rec-inline-actions">
                <a class="rec-button rec-button--primary" href="recruiter.php?section=jobs&mode=create">Create a role <span aria-hidden="true">↗</span></a>
                <a class="rec-button rec-button--secondary" href="recruiter.php?section=applicants">Review applicants</a>
            </div>
        <?php endif; ?>
    </section>

    <section class="rec-card rec-recent-card" aria-labelledby="rec-recent-applicants-title" data-reveal>
        <div class="rec-section-heading">
            <div>
                <p class="rec-eyebrow">LATEST ACTIVITY</p>
                <h2 id="rec-recent-applicants-title">Recent applicants</h2>
            </div>
            <a class="rec-text-link" href="recruiter.php?section=applicants">View all <span aria-hidden="true">↗</span></a>
        </div>

        <?php if (!$recentApplications): ?>
            <div class="rec-empty-inline">
                <span class="rec-empty-orb" aria-hidden="true"></span>
                <p>Applicants will appear here when students apply to one of your live roles.</p>
            </div>
        <?php else: ?>
            <ul class="rec-activity-list">
                <?php foreach ($recentApplications as $application): ?>
                    <li>
                        <span class="rec-activity-avatar" aria-hidden="true"><?= recruiter_h(strtoupper(mb_substr((string) $application['student_name'], 0, 1))) ?></span>
                        <span class="rec-activity-copy">
                            <strong><?= recruiter_h($application['student_name']) ?></strong>
                            <small><?= recruiter_h($application['title']) ?> · <?= recruiter_h(recruiter_status_label($application['status'])) ?></small>
                        </span>
                        <time datetime="<?= recruiter_h(date(DATE_ATOM, strtotime((string) $application['applied_at']))) ?>"><?= recruiter_h(date('M j', strtotime((string) $application['applied_at']))) ?></time>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>

<section class="rec-card rec-privacy-note" data-reveal>
    <span class="rec-privacy-icon" aria-hidden="true">✓</span>
    <p>Applicant details are shown only for applications to roles owned by your approved company. Keep student information within your hiring team.</p>
</section>
