<section class="rec-card" aria-labelledby="rec-applicants-title">
    <div class="rec-section-heading">
        <div>
            <p class="rec-eyebrow">CANDIDATE PIPELINE · <?= (int) $applicationTotal ?> MATCHES</p>
            <h2 id="rec-applicants-title">Applicants</h2>
        </div>
        <span class="rec-soft-label">Only applications to your company’s roles are shown</span>
    </div>

    <form class="rec-filter-bar" method="get" action="recruiter.php">
        <input type="hidden" name="section" value="applicants">
        <label class="rec-search-field" for="applicant-search">
            <span class="sr-only">Search applicants</span>
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.8"/><path d="m16 16 5 5"/></svg>
            <input id="applicant-search" name="q" type="search" maxlength="80" placeholder="Search candidate or role" value="<?= recruiter_h($applicationSearch) ?>">
        </label>
        <label class="sr-only" for="applicant-status-filter">Filter applicants by status</label>
        <select id="applicant-status-filter" name="status">
            <?php foreach (['all' => 'All stages', 'submitted' => 'New', 'under_review' => 'Under review', 'shortlisted' => 'Shortlisted', 'interview' => 'Interview', 'selected' => 'Selected', 'rejected' => 'Not selected', 'withdrawn' => 'Withdrawn'] as $value => $label): ?>
                <option value="<?= recruiter_h($value) ?>" <?= $applicationStatusFilter === $value ? 'selected' : '' ?>><?= recruiter_h($label) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="rec-button rec-button--secondary rec-button--compact" type="submit">Filter</button>
    </form>

    <?php if (!$applications): ?>
        <div class="rec-empty-state">
            <span class="rec-empty-orb" aria-hidden="true"></span>
            <h3><?= $applicationSearch !== '' || $applicationStatusFilter !== 'all' ? 'No matching applicants' : 'No applications yet' ?></h3>
            <p><?= $applicationSearch !== '' || $applicationStatusFilter !== 'all' ? 'Try another search or stage filter.' : 'New applications to your published roles will appear here.' ?></p>
        </div>
    <?php else: ?>
        <div class="rec-applicant-list">
            <?php foreach ($applications as $application): ?>
                <?php $nextStatuses = $applicationTransitions[$application['status']] ?? []; ?>
                <article class="rec-applicant-card">
                    <div class="rec-applicant-head">
                        <span class="rec-applicant-avatar" aria-hidden="true"><?= recruiter_h(strtoupper(mb_substr((string) $application['student_name'], 0, 1))) ?></span>
                        <div class="rec-applicant-identity">
                            <h3><?= recruiter_h($application['student_name']) ?></h3>
                            <p><?= recruiter_h($application['title']) ?></p>
                        </div>
                        <span class="rec-status-pill rec-status-pill--<?= recruiter_h($application['status']) ?>">
                            <?= recruiter_h(recruiter_status_label($application['status'])) ?>
                        </span>
                    </div>

                    <div class="rec-candidate-facts">
                        <span><small>Branch</small><strong><?= recruiter_h((string) ($application['branch'] ?: 'Not provided')) ?></strong></span>
                        <span><small>CGPA</small><strong><?= recruiter_h((string) ($application['cgpa'] ?? 'Not provided')) ?></strong></span>
                        <span><small>Graduation</small><strong><?= recruiter_h((string) ($application['graduation_year'] ?? 'Not provided')) ?></strong></span>
                        <span><small>Applied</small><strong><?= recruiter_h(date('M j, Y', strtotime((string) $application['applied_at']))) ?></strong></span>
                        <span><small>Contact</small><strong><a class="rec-candidate-email" href="mailto:<?= recruiter_h($application['email']) ?>"><?= recruiter_h($application['email']) ?></a></strong></span>
                    </div>

                    <?php if (trim((string) ($application['cover_note'] ?? '')) !== ''): ?>
                        <div class="rec-cover-note">
                            <span>Candidate note</span>
                            <p><?= nl2br(recruiter_h((string) $application['cover_note'])) ?></p>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($applicationHistory[(int) $application['id']])): ?>
                        <details class="rec-history-details">
                            <summary>Recent stage history</summary>
                            <ol class="rec-history-list">
                                <?php foreach ($applicationHistory[(int) $application['id']] as $event): ?>
                                    <li>
                                        <span class="rec-history-dot" aria-hidden="true"></span>
                                        <span><strong><?= recruiter_h(recruiter_status_label($event['new_status'])) ?></strong>
                                            <small><?= recruiter_h(date('M j, Y · g:i A T', strtotime((string) $event['created_at']))) ?><?= $event['note'] ? ' · ' . recruiter_h($event['note']) : '' ?></small>
                                        </span>
                                    </li>
                                <?php endforeach; ?>
                            </ol>
                        </details>
                    <?php endif; ?>

                    <div class="rec-applicant-actions">
                        <?php if ($nextStatuses): ?>
                            <form class="rec-status-form" method="post" action="recruiter.php?section=applicants">
                                <input type="hidden" name="_csrf" value="<?= recruiter_h($csrf) ?>">
                                <input type="hidden" name="action" value="change_application">
                                <input type="hidden" name="return_section" value="applicants">
                                <input type="hidden" name="application_id" value="<?= (int) $application['id'] ?>">
                                <label class="sr-only" for="application-status-<?= (int) $application['id'] ?>">Next stage for <?= recruiter_h($application['student_name']) ?></label>
                                <select id="application-status-<?= (int) $application['id'] ?>" name="status" required>
                                    <?php foreach ($nextStatuses as $nextStatus): ?>
                                        <option value="<?= recruiter_h($nextStatus) ?>"><?= recruiter_h(recruiter_status_label($nextStatus)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <label class="sr-only" for="application-note-<?= (int) $application['id'] ?>">Optional note for this stage update</label>
                                <input id="application-note-<?= (int) $application['id'] ?>" name="note" maxlength="500" placeholder="Optional private note">
                                <button class="rec-button rec-button--secondary rec-button--compact" type="submit">Update stage</button>
                            </form>
                        <?php else: ?>
                            <p class="rec-terminal-note">This application is at a final stage.</p>
                        <?php endif; ?>

                        <?php if (in_array($application['status'], ['shortlisted', 'interview'], true)): ?>
                            <?php if (!empty($application['has_upcoming_interview'])): ?>
                                <a class="rec-button rec-button--secondary rec-button--compact" href="recruiter.php?section=interviews&when=upcoming">Interview scheduled · manage it <span aria-hidden="true">↗</span></a>
                            <?php else: ?>
                                <a class="rec-button rec-button--primary rec-button--compact" href="recruiter.php?section=interviews&application=<?= (int) $application['id'] ?>">Schedule interview <span aria-hidden="true">↗</span></a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <?php if ($applicationPages > 1): ?>
            <nav class="rec-pagination" aria-label="Applicant list pages">
                <?php if ($applicationPage > 1): ?>
                    <a href="recruiter.php?section=applicants&status=<?= recruiter_h($applicationStatusFilter) ?>&q=<?= rawurlencode($applicationSearch) ?>&app_page=<?= $applicationPage - 1 ?>">Previous</a>
                <?php endif; ?>
                <span>Page <?= (int) $applicationPage ?> of <?= (int) $applicationPages ?></span>
                <?php if ($applicationPage < $applicationPages): ?>
                    <a href="recruiter.php?section=applicants&status=<?= recruiter_h($applicationStatusFilter) ?>&q=<?= rawurlencode($applicationSearch) ?>&app_page=<?= $applicationPage + 1 ?>">Next</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
