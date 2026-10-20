<section class="admin-stat-grid admin-stat-grid--five" aria-label="Offer pipeline">
    <article class="admin-stat"><p>Offers sent</p><strong><?= (int) $offersSummary['offered'] ?></strong></article>
    <article class="admin-stat"><p>Accepted</p><strong><?= (int) $offersSummary['accepted'] ?></strong></article>
    <article class="admin-stat"><p>Joined</p><strong><?= (int) $offersSummary['joined'] ?></strong></article>
    <article class="admin-stat"><p>Declined</p><strong><?= (int) $offersSummary['declined'] ?></strong></article>
    <article class="admin-stat">
        <p>Median CTC</p>
        <strong><?= admin_h(admin_money($offersSummary['median_ctc'] === null ? null : (string) $offersSummary['median_ctc'])) ?></strong>
    </article>
</section>

<?php if (!$offersReady): ?>
    <section class="admin-card">
        <h2>Offer tracking is not available yet</h2>
        <p class="admin-muted">Run <code>database/migrations/2026-10-20-admin-portal-offers.sql</code> in phpMyAdmin. Selection statuses keep working meanwhile.</p>
    </section>
<?php else: ?>
    <section class="admin-card" aria-labelledby="admin-record-offer-title">
        <div class="admin-section-heading">
            <div>
                <p class="admin-eyebrow">RECORD</p>
                <h2 id="admin-record-offer-title">Log an offer</h2>
            </div>
            <span class="admin-pill"><?= count($pendingOffers) ?> selected without an offer</span>
        </div>
        <p class="admin-muted">
            Offers can only be recorded for applications the recruiter marked <strong>selected</strong>. CTC is stored in
            LPA and is what the reports use — it is never inferred from a job description.
        </p>

        <?php if (!$pendingOffers): ?>
            <p class="admin-empty">No selected candidates are waiting for an offer record.</p>
        <?php else: ?>
            <form class="admin-form-grid" method="post">
                <input type="hidden" name="_csrf" value="<?= admin_h($csrf) ?>">
                <input type="hidden" name="action" value="offer_create">
                <input type="hidden" name="return_section" value="placements">
                <label class="admin-field admin-field--wide">
                    <span>Candidate</span>
                    <select name="application_id" required>
                        <?php foreach ($pendingOffers as $candidate): ?>
                            <option value="<?= (int) $candidate['id'] ?>">
                                <?= admin_h($candidate['name']) ?> — <?= admin_h($candidate['title']) ?> · <?= admin_h($candidate['company_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="admin-field">
                    <span>CTC (LPA)</span>
                    <input name="ctc_lpa" type="number" min="0" max="500" step="0.01" inputmode="decimal" placeholder="e.g. 6.5">
                </label>
                <label class="admin-field">
                    <span>Offer date</span>
                    <input name="offer_date" type="date" value="<?= admin_h(date('Y-m-d')) ?>">
                </label>
                <label class="admin-field">
                    <span>State</span>
                    <select name="status">
                        <option value="offered">Offer sent</option>
                        <option value="accepted">Already accepted</option>
                    </select>
                </label>
                <div class="admin-field admin-field--actions">
                    <button class="admin-button" type="submit">Record offer</button>
                </div>
            </form>
        <?php endif; ?>
    </section>

    <section class="admin-card" aria-labelledby="admin-offers-title">
        <div class="admin-section-heading">
            <div>
                <p class="admin-eyebrow">PIPELINE</p>
                <h2 id="admin-offers-title">Offers</h2>
            </div>
            <a class="admin-text-link" href="admin.php?section=reports&export=offers">Export CSV</a>
        </div>

        <nav class="admin-chips" aria-label="Filter offers by state">
            <?php
            $offerFilters = ['' => 'All', 'offered' => 'Sent', 'accepted' => 'Accepted', 'declined' => 'Declined', 'joined' => 'Joined', 'revoked' => 'Revoked'];
            foreach ($offerFilters as $value => $label):
                $href = 'admin.php?section=placements' . ($value !== '' ? '&offer_status=' . $value : '');
                ?>
                <a class="admin-chip <?= $offerStatusFilter === $value ? 'is-active' : '' ?>" href="<?= admin_h($href) ?>"
                   <?= $offerStatusFilter === $value ? 'aria-current="page"' : '' ?>><?= admin_h($label) ?></a>
            <?php endforeach; ?>
        </nav>

        <?php if (!$offers): ?>
            <p class="admin-empty">No offers recorded in this view.</p>
        <?php else: ?>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th scope="col">Candidate</th>
                            <th scope="col">Role</th>
                            <th scope="col">CTC</th>
                            <th scope="col">Offer date</th>
                            <th scope="col">State</th>
                            <th scope="col">Update</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($offers as $offer): ?>
                            <tr>
                                <td>
                                    <strong><?= admin_h($offer['name']) ?></strong>
                                    <span class="admin-cell-sub"><?= admin_h($offer['email']) ?></span>
                                </td>
                                <td>
                                    <?= admin_h($offer['title']) ?>
                                    <span class="admin-cell-sub"><?= admin_h($offer['company_name']) ?></span>
                                </td>
                                <td><?= admin_h(admin_money($offer['ctc_lpa'] !== null ? (string) $offer['ctc_lpa'] : null)) ?></td>
                                <td>
                                    <?= admin_h($offer['offer_date'] ?: '—') ?>
                                    <?php if ($offer['joined_on']): ?>
                                        <span class="admin-cell-sub">Joined <?= admin_h((string) $offer['joined_on']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="admin-pill <?= $offer['status'] === 'joined' ? 'admin-pill--ok' : ($offer['status'] === 'declined' || $offer['status'] === 'revoked' ? 'admin-pill--bad' : '') ?>">
                                        <?= admin_h(admin_status_label((string) $offer['status'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <form class="admin-decision" method="post">
                                        <input type="hidden" name="_csrf" value="<?= admin_h($csrf) ?>">
                                        <input type="hidden" name="action" value="offer_update">
                                        <input type="hidden" name="return_section" value="placements">
                                        <input type="hidden" name="id" value="<?= (int) $offer['id'] ?>">
                                        <label class="sr-only" for="offer-status-<?= (int) $offer['id'] ?>">Offer state for <?= admin_h($offer['name']) ?></label>
                                        <select id="offer-status-<?= (int) $offer['id'] ?>" name="status">
                                            <?php foreach (['offered' => 'Offer sent', 'accepted' => 'Accepted', 'declined' => 'Declined', 'joined' => 'Joined', 'revoked' => 'Revoked'] as $value => $label): ?>
                                                <option value="<?= admin_h($value) ?>" <?= $offer['status'] === $value ? 'selected' : '' ?>><?= admin_h($label) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <label class="sr-only" for="offer-joined-<?= (int) $offer['id'] ?>">Joining date for <?= admin_h($offer['name']) ?></label>
                                        <input id="offer-joined-<?= (int) $offer['id'] ?>" name="joined_on" type="date" value="<?= admin_h((string) ($offer['joined_on'] ?? '')) ?>">
                                        <button class="admin-button admin-button--small" type="submit">Save</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>
