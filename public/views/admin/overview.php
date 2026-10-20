<section class="admin-stat-grid" aria-label="Placement counters">
    <?php foreach ($stats as $label => $value): ?>
        <article class="admin-stat">
            <p><?= admin_h($label) ?></p>
            <strong><?= $value === null ? '—' : admin_h((string) $value) ?></strong>
        </article>
    <?php endforeach; ?>
</section>

<div class="admin-split">
    <section class="admin-card" aria-labelledby="admin-queue-title">
        <div class="admin-section-heading">
            <div>
                <p class="admin-eyebrow">QUEUE</p>
                <h2 id="admin-queue-title">Waiting on you</h2>
            </div>
            <a class="admin-text-link" href="admin.php?section=approvals">Open approvals</a>
        </div>

        <?php if (!$approvalUsers && !$approvalCompanies): ?>
            <p class="admin-muted">Nothing is waiting for review. New student and recruiter accounts land here.</p>
        <?php else: ?>
            <ul class="admin-queue">
                <?php foreach ($approvalUsers as $row): ?>
                    <li>
                        <span class="admin-queue-main">
                            <strong><?= admin_h($row['name']) ?></strong>
                            <span class="admin-muted"><?= admin_h($row['email']) ?> · <?= admin_h(ucfirst($row['role'])) ?></span>
                        </span>
                        <span class="admin-age"><?= admin_h(admin_age_label($row['created_at'])) ?></span>
                    </li>
                <?php endforeach; ?>
                <?php foreach ($approvalCompanies as $company): ?>
                    <li>
                        <span class="admin-queue-main">
                            <strong><?= admin_h($company['name']) ?></strong>
                            <span class="admin-muted">Company · <?= (int) $company['recruiter_count'] ?> recruiter<?= (int) $company['recruiter_count'] === 1 ? '' : 's' ?></span>
                        </span>
                        <span class="admin-age"><?= admin_h(admin_age_label($company['created_at'])) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="admin-card" aria-labelledby="admin-placement-title">
        <div class="admin-section-heading">
            <div>
                <p class="admin-eyebrow">PLACEMENTS</p>
                <h2 id="admin-placement-title">Offer pipeline</h2>
            </div>
            <a class="admin-text-link" href="admin.php?section=placements">Record an offer</a>
        </div>

        <?php if (!$offersReady): ?>
            <p class="admin-muted">Offer tracking switches on after the offers migration is applied.</p>
        <?php else: ?>
            <dl class="admin-metrics">
                <div><dt>Offers sent</dt><dd><?= (int) $offersSummary['offered'] ?></dd></div>
                <div><dt>Accepted</dt><dd><?= (int) $offersSummary['accepted'] ?></dd></div>
                <div><dt>Joined</dt><dd><?= (int) $offersSummary['joined'] ?></dd></div>
                <div><dt>Median CTC</dt><dd><?= admin_h(admin_money($offersSummary['median_ctc'] === null ? null : (string) $offersSummary['median_ctc'])) ?></dd></div>
            </dl>
            <p class="admin-muted admin-small">Joining is recorded separately from selection, so the placement count is not inflated by offers that were declined.</p>
        <?php endif; ?>
    </section>
</div>

<section class="admin-card" aria-labelledby="admin-activity-title">
    <div class="admin-section-heading">
        <div>
            <p class="admin-eyebrow">AUDIT</p>
            <h2 id="admin-activity-title">Recent activity</h2>
        </div>
        <a class="admin-text-link" href="admin.php?section=governance">Full trail</a>
    </div>
    <?php if (!$recentActivity): ?>
        <p class="admin-muted">No audited actions yet. Approvals, job moderation, imports and offers are recorded here.</p>
    <?php else: ?>
        <ol class="admin-timeline">
            <?php foreach ($recentActivity as $entry): ?>
                <li>
                    <span class="admin-timeline-dot" aria-hidden="true"></span>
                    <span class="admin-timeline-body">
                        <strong><?= admin_h(str_replace('_', ' ', $entry['action'])) ?></strong>
                        <span class="admin-muted">
                            <?= admin_h($entry['actor_name'] ?? 'System') ?>
                            <?php if ($entry['entity_type']): ?>· <?= admin_h((string) $entry['entity_type']) ?><?php endif; ?>
                            <?php if ($entry['entity_id']): ?> #<?= (int) $entry['entity_id'] ?><?php endif; ?>
                            · <?= admin_h((string) $entry['created_at']) ?>
                        </span>
                    </span>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
</section>
