<section class="admin-card" aria-labelledby="admin-audit-title">
    <div class="admin-section-heading">
        <div>
            <p class="admin-eyebrow">TRAIL</p>
            <h2 id="admin-audit-title">Audit log</h2>
        </div>
        <span class="admin-pill"><?= count($auditEntries) ?> entries</span>
    </div>
    <p class="admin-muted">
        Every approval, moderation action, import and offer change is written here with the actor and a hashed IP.
        Search by action name, for example <code>user_</code>, <code>company_</code>, <code>offer_</code>, or <code>imported</code>.
    </p>

    <form class="admin-search" method="get" action="admin.php">
        <input type="hidden" name="section" value="governance">
        <label class="sr-only" for="audit-action">Filter by action</label>
        <input id="audit-action" name="audit_action" value="<?= admin_h($auditAction) ?>" maxlength="40" placeholder="Filter by action, e.g. offer_">
        <button class="admin-button admin-button--small" type="submit">Filter</button>
        <?php if ($auditAction !== ''): ?>
            <a class="admin-text-link" href="admin.php?section=governance">Clear</a>
        <?php endif; ?>
    </form>

    <?php if (!$auditEntries): ?>
        <p class="admin-empty">No audit entries match that filter.</p>
    <?php else: ?>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th scope="col">When</th>
                        <th scope="col">Actor</th>
                        <th scope="col">Action</th>
                        <th scope="col">Entity</th>
                        <th scope="col">Metadata</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($auditEntries as $entry): ?>
                        <tr>
                            <td><?= admin_h((string) $entry['created_at']) ?></td>
                            <td><?= admin_h($entry['actor_name'] ?? 'System') ?></td>
                            <td><code><?= admin_h((string) $entry['action']) ?></code></td>
                            <td>
                                <?= admin_h($entry['entity_type'] ?: '—') ?>
                                <?php if ($entry['entity_id']): ?>
                                    <span class="admin-cell-sub">#<?= (int) $entry['entity_id'] ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="admin-metadata">
                                <?php if (!empty($entry['metadata_json'])): ?>
                                    <code><?= admin_h((string) $entry['metadata_json']) ?></code>
                                <?php else: ?>
                                    <span class="admin-muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
