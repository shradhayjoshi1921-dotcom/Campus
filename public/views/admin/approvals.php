<section class="admin-card" aria-labelledby="admin-accounts-title">
    <div class="admin-section-heading">
        <div>
            <p class="admin-eyebrow">ACCOUNTS</p>
            <h2 id="admin-accounts-title">Awaiting review</h2>
        </div>
        <span class="admin-pill admin-pill--warn"><?= count($approvalUsers) ?> in queue</span>
    </div>
    <p class="admin-muted">Every decision sends a notification. Add a note whenever you reject or ask for a change — it is the only thing the applicant will see.</p>

    <?php if (!$approvalUsers): ?>
        <p class="admin-empty">No accounts are waiting. New sign-ups arrive here automatically.</p>
    <?php else: ?>
        <?php $pendingOnly = array_values(array_filter($approvalUsers, static fn (array $row): bool => $row['status'] === 'pending')); ?>
        <?php if (count($pendingOnly) > 1): ?>
            <form class="admin-bulk" method="post" onsubmit="return confirm('Approve all <?= count($pendingOnly) ?> pending accounts? Each account holder will be notified.');">
                <input type="hidden" name="_csrf" value="<?= admin_h($csrf) ?>">
                <input type="hidden" name="action" value="users_bulk">
                <input type="hidden" name="return_section" value="approvals">
                <input type="hidden" name="decision" value="approve">
                <input type="hidden" name="bulk_scope" value="all_pending">
                <button class="admin-button admin-button--quiet" type="submit">Approve all <?= count($pendingOnly) ?> pending</button>
                <span class="admin-muted admin-small">Use this only when the batch is verified against your admission or recruiter list.</span>
            </form>
        <?php endif; ?>

        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th scope="col">Account</th>
                        <th scope="col">Role</th>
                        <th scope="col">Submitted</th>
                        <th scope="col">Decision</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($approvalUsers as $row): ?>
                        <tr>
                            <td>
                                <strong><?= admin_h($row['name']) ?></strong>
                                <span class="admin-cell-sub"><?= admin_h($row['email']) ?></span>
                            </td>
                            <td>
                                <?= admin_h(ucfirst($row['role'])) ?>
                                <span class="admin-cell-sub"><?= admin_h(admin_status_label($row['status'])) ?></span>
                            </td>
                            <td>
                                <?= admin_h(admin_age_label($row['created_at'])) ?>
                                <?php if (admin_age_days($row['created_at']) > 7): ?>
                                    <span class="admin-cell-sub admin-warn-text">Older than a week</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form class="admin-decision" method="post">
                                    <input type="hidden" name="_csrf" value="<?= admin_h($csrf) ?>">
                                    <input type="hidden" name="action" value="user_decision">
                                    <input type="hidden" name="return_section" value="approvals">
                                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                    <label class="sr-only" for="decision-<?= (int) $row['id'] ?>">Decision for <?= admin_h($row['name']) ?></label>
                                    <select id="decision-<?= (int) $row['id'] ?>" name="decision">
                                        <option value="approve">Approve</option>
                                        <option value="request_changes">Request changes</option>
                                        <option value="reject">Reject</option>
                                        <option value="suspend">Suspend</option>
                                        <?php if ($row['status'] === 'suspended'): ?>
                                            <option value="reactivate">Reactivate</option>
                                        <?php endif; ?>
                                    </select>
                                    <label class="sr-only" for="note-<?= (int) $row['id'] ?>">Note for <?= admin_h($row['name']) ?></label>
                                    <input id="note-<?= (int) $row['id'] ?>" name="note" maxlength="500" placeholder="Reason (sent to the applicant)">
                                    <button class="admin-button admin-button--small" type="submit">Apply</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="admin-card" aria-labelledby="admin-companies-title">
    <div class="admin-section-heading">
        <div>
            <p class="admin-eyebrow">COMPANIES</p>
            <h2 id="admin-companies-title">Company review</h2>
        </div>
        <span class="admin-pill admin-pill--warn"><?= count($approvalCompanies) ?> in queue</span>
    </div>
    <p class="admin-muted">A company must be approved before any of its jobs can be published. Requesting changes keeps it in review without deleting the recruiter's work.</p>

    <?php if (!$approvalCompanies): ?>
        <p class="admin-empty">No company profiles are waiting.</p>
    <?php else: ?>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th scope="col">Company</th>
                        <th scope="col">Recruiters</th>
                        <th scope="col">Submitted</th>
                        <th scope="col">Decision</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($approvalCompanies as $company): ?>
                        <tr>
                            <td>
                                <strong><?= admin_h($company['name']) ?></strong>
                                <span class="admin-cell-sub">
                                    <?= admin_h($company['industry'] ?: 'Industry not set') ?>
                                    <?php if (!empty($company['website'])): ?> · <?= admin_h((string) $company['website']) ?><?php endif; ?>
                                </span>
                            </td>
                            <td><?= (int) $company['recruiter_count'] ?></td>
                            <td>
                                <?= admin_h(admin_age_label($company['created_at'])) ?>
                                <span class="admin-cell-sub"><?= admin_h(admin_status_label((string) $company['approval_status'])) ?></span>
                            </td>
                            <td>
                                <form class="admin-decision" method="post">
                                    <input type="hidden" name="_csrf" value="<?= admin_h($csrf) ?>">
                                    <input type="hidden" name="action" value="company_decision">
                                    <input type="hidden" name="return_section" value="approvals">
                                    <input type="hidden" name="id" value="<?= (int) $company['id'] ?>">
                                    <label class="sr-only" for="company-decision-<?= (int) $company['id'] ?>">Decision for <?= admin_h($company['name']) ?></label>
                                    <select id="company-decision-<?= (int) $company['id'] ?>" name="decision">
                                        <option value="approve">Approve</option>
                                        <option value="request_changes">Request changes</option>
                                        <option value="reject">Reject</option>
                                    </select>
                                    <label class="sr-only" for="company-note-<?= (int) $company['id'] ?>">Note for <?= admin_h($company['name']) ?></label>
                                    <input id="company-note-<?= (int) $company['id'] ?>" name="note" maxlength="500" placeholder="Reason (sent to recruiters)">
                                    <button class="admin-button admin-button--small" type="submit">Apply</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
