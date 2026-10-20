<section class="admin-card" aria-labelledby="admin-publish-title">
    <div class="admin-section-heading">
        <div>
            <p class="admin-eyebrow">PUBLISH</p>
            <h2 id="admin-publish-title">New announcement</h2>
        </div>
        <span class="admin-pill">Delivered as in-app notifications</span>
    </div>
    <p class="admin-muted">
        Announcements reach active accounts only. There is no mailer in this project, so delivery means a notification
        in the portal — tell students where to look.
    </p>
    <form class="admin-form-grid" method="post">
        <input type="hidden" name="_csrf" value="<?= admin_h($csrf) ?>">
        <input type="hidden" name="action" value="announcement">
        <input type="hidden" name="return_section" value="comms">
        <label class="admin-field admin-field--wide">
            <span>Title</span>
            <input name="title" required maxlength="180" placeholder="e.g. TCS drive registration closes Friday">
        </label>
        <label class="admin-field admin-field--wide">
            <span>Message</span>
            <textarea name="body" required rows="5" maxlength="10000" placeholder="Who needs to act, by when, and where."></textarea>
        </label>
        <label class="admin-field">
            <span>Audience</span>
            <select name="audience">
                <option value="students">Students</option>
                <option value="recruiters">Recruiters</option>
                <option value="tpo">TPO team</option>
                <option value="all">Everyone</option>
            </select>
        </label>
        <div class="admin-field admin-field--actions">
            <button class="admin-button" type="submit">Publish announcement</button>
        </div>
    </form>
</section>

<section class="admin-card" aria-labelledby="admin-sent-title">
    <div class="admin-section-heading">
        <div>
            <p class="admin-eyebrow">HISTORY</p>
            <h2 id="admin-sent-title">Published</h2>
        </div>
        <span class="admin-pill"><?= count($announcements) ?> recent</span>
    </div>

    <?php if (!$announcements): ?>
        <p class="admin-empty">Nothing published yet.</p>
    <?php else: ?>
        <ul class="admin-announcements">
            <?php foreach ($announcements as $announcement): ?>
                <li>
                    <div class="admin-announcement-head">
                        <strong><?= admin_h($announcement['title']) ?></strong>
                        <span class="admin-pill">
                            <?= admin_h(match ($announcement['audience']) {
                                'students' => 'Students',
                                'recruiters' => 'Recruiters',
                                'tpo' => 'TPO',
                                default => 'Everyone',
                            }) ?>
                        </span>
                    </div>
                    <p><?= nl2br(admin_h($announcement['body'])) ?></p>
                    <p class="admin-muted admin-small">
                        <?= admin_h($announcement['author'] ?? 'Unknown') ?>
                        · <?= admin_h((string) ($announcement['published_at'] ?: $announcement['created_at'])) ?>
                        · <?= (int) $announcement['delivered'] ?> delivered
                    </p>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
