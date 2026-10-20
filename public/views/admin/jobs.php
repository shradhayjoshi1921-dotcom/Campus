<section class="admin-card" aria-labelledby="admin-jobs-title">
    <div class="admin-section-heading">
        <div>
            <p class="admin-eyebrow">MODERATION</p>
            <h2 id="admin-jobs-title">Jobs</h2>
        </div>
        <span class="admin-pill"><?= count($jobs) ?> shown</span>
    </div>
    <p class="admin-muted">Recruiters publish their own roles. Pausing or closing a job here stops new applications immediately and is recorded in the audit trail.</p>

    <nav class="admin-chips lg-group" data-glass-group aria-label="Filter jobs by status">
        <span class="lg-indicator" aria-hidden="true">
            <span class="lg-smoke"></span>
            <span class="lg-specular"></span>
            <span class="lg-rim"></span>
            <span class="lg-caustic"></span>
            <span class="lg-glint"></span>
        </span>
        <?php
        $jobFilters = ['' => 'All', 'published' => 'Published', 'draft' => 'Drafts', 'paused' => 'Paused', 'closed' => 'Closed'];
        foreach ($jobFilters as $value => $label):
            $href = 'admin.php?section=jobs' . ($value !== '' ? '&job_status=' . $value : '');
            ?>
            <a class="admin-chip lg-option <?= $jobStatusFilter === $value ? 'is-active' : '' ?>" data-lg-option href="<?= admin_h($href) ?>"
               <?= $jobStatusFilter === $value ? 'aria-current="page"' : '' ?>><?= admin_h($label) ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if (!$jobs): ?>
        <p class="admin-empty">No jobs in this view.</p>
    <?php else: ?>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th scope="col">Role</th>
                        <th scope="col">Company</th>
                        <th scope="col">Applications</th>
                        <th scope="col">Closes</th>
                        <th scope="col">Status</th>
                        <th scope="col">Moderation</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($jobs as $job): ?>
                        <tr>
                            <td>
                                <strong><?= admin_h($job['title']) ?></strong>
                                <span class="admin-cell-sub">ID <?= (int) $job['id'] ?></span>
                            </td>
                            <td>
                                <?= admin_h($job['company_name']) ?>
                                <span class="admin-cell-sub">
                                    <?= $job['approval_status'] === 'approved'
                                        ? 'Approved'
                                        : admin_h(ucfirst((string) $job['approval_status'])) ?>
                                </span>
                            </td>
                            <td><?= (int) $job['applications'] ?></td>
                            <td><?= admin_h($job['closes_at'] ?: '—') ?></td>
                            <td>
                                <span class="admin-pill <?= $job['status'] === 'published' ? 'admin-pill--ok' : ($job['status'] === 'closed' ? 'admin-pill--bad' : '') ?>">
                                    <?= admin_h(admin_status_label((string) $job['status'])) ?>
                                </span>
                            </td>
                            <td>
                                <form class="admin-decision" method="post">
                                    <input type="hidden" name="_csrf" value="<?= admin_h($csrf) ?>">
                                    <input type="hidden" name="action" value="job_decision">
                                    <input type="hidden" name="return_section" value="jobs">
                                    <input type="hidden" name="id" value="<?= (int) $job['id'] ?>">
                                    <label class="sr-only" for="job-decision-<?= (int) $job['id'] ?>">Moderation for <?= admin_h($job['title']) ?></label>
                                    <select id="job-decision-<?= (int) $job['id'] ?>" name="decision">
                                        <option value="publish">Publish</option>
                                        <option value="pause">Pause</option>
                                        <option value="draft">Back to draft</option>
                                        <option value="close">Close</option>
                                    </select>
                                    <label class="sr-only" for="job-note-<?= (int) $job['id'] ?>">Audit note for <?= admin_h($job['title']) ?></label>
                                    <input id="job-note-<?= (int) $job['id'] ?>" name="note" maxlength="500" placeholder="Audit note (optional)">
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
