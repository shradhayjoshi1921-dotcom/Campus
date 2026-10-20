<div class="rec-interview-layout">
    <section class="rec-card rec-card--schedule" aria-labelledby="rec-schedule-title">
        <div class="rec-section-heading">
            <div>
                <p class="rec-eyebrow">NEXT CONVERSATION</p>
                <h2 id="rec-schedule-title">Schedule an interview</h2>
            </div>
            <span class="rec-stat-icon rec-stat-icon--amber" aria-hidden="true">
                <svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="3"/><path d="M16 3v4M8 3v4M3 10h18"/></svg>
            </span>
        </div>

        <?php if (!$interviewCandidates): ?>
            <div class="rec-empty-inline">
                <span class="rec-empty-orb" aria-hidden="true"></span>
                <p>Shortlist a candidate first. Eligible candidates will be available here for interview scheduling.</p>
            </div>
        <?php else: ?>
            <form class="rec-form" method="post" action="recruiter.php?section=interviews">
                <input type="hidden" name="_csrf" value="<?= recruiter_h($csrf) ?>">
                <input type="hidden" name="action" value="schedule_interview">
                <input type="hidden" name="return_section" value="interviews">
                <label for="interview-application">Candidate and role
                    <select id="interview-application" name="application_id" required>
                        <option value="">Choose a shortlisted candidate</option>
                        <?php foreach ($interviewCandidates as $candidate): ?>
                            <option value="<?= (int) $candidate['id'] ?>" <?= (int) $candidate['id'] === $scheduleApplicationId ? 'selected' : '' ?>>
                                <?= recruiter_h($candidate['student_name']) ?> · <?= recruiter_h($candidate['title']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="rec-form-grid">
                    <label for="interview-datetime">Date and time (IST)
                        <input id="interview-datetime" type="datetime-local" name="scheduled_at" required>
                    </label>
                    <label for="interview-mode">Format
                        <select id="interview-mode" name="mode">
                            <option value="">Choose a format</option>
                            <option value="Online">Online</option>
                            <option value="On-site">On-site</option>
                            <option value="Phone">Phone</option>
                        </select>
                    </label>
                </div>
                <label for="interview-location">Meeting link or location
                    <input id="interview-location" type="text" name="location_or_link" maxlength="500" placeholder="Add a meeting link or venue">
                </label>
                <button class="rec-button rec-button--primary" type="submit">Confirm interview <span aria-hidden="true">↗</span></button>
                <p class="rec-form-note">The student receives a portal notification when the interview is scheduled.</p>
            </form>
        <?php endif; ?>
    </section>

    <section class="rec-card rec-card--interviews" aria-labelledby="rec-interviews-title">
        <div class="rec-section-heading">
            <div>
                <p class="rec-eyebrow">SCHEDULE</p>
                <h2 id="rec-interviews-title">Interview activity</h2>
            </div>
            <form class="rec-filter-select" method="get" action="recruiter.php">
                <input type="hidden" name="section" value="interviews">
                <label class="sr-only" for="interview-time-filter">Filter interviews by time</label>
                <select id="interview-time-filter" class="rec-inline-select" name="when">
                    <option value="upcoming" <?= $interviewFilter === 'upcoming' ? 'selected' : '' ?>>Upcoming</option>
                    <option value="past" <?= $interviewFilter === 'past' ? 'selected' : '' ?>>Past and completed</option>
                    <option value="all" <?= $interviewFilter === 'all' ? 'selected' : '' ?>>All interviews</option>
                </select>
                <button class="rec-button rec-button--secondary rec-button--compact" type="submit">Apply</button>
            </form>
        </div>

        <?php if (!$interviews): ?>
            <div class="rec-empty-state rec-empty-state--compact">
                <span class="rec-empty-orb" aria-hidden="true"></span>
                <h3>No interviews in this view</h3>
                <p>Scheduled interviews and their latest status will appear here.</p>
            </div>
        <?php else: ?>
            <div class="rec-interview-list">
                <?php foreach ($interviews as $interview): ?>
                    <article class="rec-interview-card">
                        <div class="rec-interview-date">
                            <strong><?= recruiter_h(date('d', strtotime((string) $interview['scheduled_at']))) ?></strong>
                            <span><?= recruiter_h(date('M', strtotime((string) $interview['scheduled_at']))) ?></span>
                        </div>
                        <div class="rec-interview-details">
                            <div class="rec-interview-title-row">
                                <div>
                                    <h3><?= recruiter_h($interview['student_name']) ?></h3>
                                    <p><?= recruiter_h($interview['title']) ?></p>
                                </div>
                                <span class="rec-status-pill rec-status-pill--<?= recruiter_h($interview['status']) ?>"><?= recruiter_h(recruiter_status_label($interview['status'])) ?></span>
                            </div>
                            <p class="rec-interview-meta">
                                <time datetime="<?= recruiter_h(date(DATE_ATOM, strtotime((string) $interview['scheduled_at']))) ?>"><?= recruiter_h(date('M j, Y · g:i A T', strtotime((string) $interview['scheduled_at']))) ?></time>
                                <?php if ($interview['mode']): ?><span><?= recruiter_h($interview['mode']) ?></span><?php endif; ?>
                                <?php if ($interview['location_or_link']): ?>
                                    <?php
                                    $meetingUrl = (string) $interview['location_or_link'];
                                    $meetingScheme = strtolower((string) parse_url($meetingUrl, PHP_URL_SCHEME));
                                    ?>
                                    <?php if (filter_var($meetingUrl, FILTER_VALIDATE_URL) && in_array($meetingScheme, ['http', 'https'], true)): ?>
                                        <a class="rec-meeting-link" href="<?= recruiter_h($meetingUrl) ?>" target="_blank" rel="noopener noreferrer">Open meeting link</a>
                                    <?php else: ?>
                                        <span><?= recruiter_h($meetingUrl) ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </p>
                            <?php if ($interview['status'] === 'scheduled'): ?>
                                <div class="rec-interview-actions">
                                    <details class="rec-reschedule-details">
                                        <summary>Reschedule</summary>
                                        <form class="rec-form rec-reschedule-form" method="post" action="recruiter.php?section=interviews">
                                            <input type="hidden" name="_csrf" value="<?= recruiter_h($csrf) ?>">
                                            <input type="hidden" name="action" value="reschedule_interview">
                                            <input type="hidden" name="return_section" value="interviews">
                                            <input type="hidden" name="interview_id" value="<?= (int) $interview['id'] ?>">
                                            <label for="reschedule-datetime-<?= (int) $interview['id'] ?>">New date and time (IST)
                                                <input id="reschedule-datetime-<?= (int) $interview['id'] ?>" type="datetime-local" name="scheduled_at" required>
                                            </label>
                                            <label for="reschedule-mode-<?= (int) $interview['id'] ?>">Format
                                                <input id="reschedule-mode-<?= (int) $interview['id'] ?>" name="mode" maxlength="60" value="<?= recruiter_h((string) ($interview['mode'] ?? '')) ?>">
                                            </label>
                                            <label for="reschedule-location-<?= (int) $interview['id'] ?>">Link or location
                                                <input id="reschedule-location-<?= (int) $interview['id'] ?>" name="location_or_link" maxlength="500" value="<?= recruiter_h((string) ($interview['location_or_link'] ?? '')) ?>">
                                            </label>
                                            <button class="rec-button rec-button--secondary rec-button--compact" type="submit">Save new time</button>
                                        </form>
                                    </details>
                                    <form method="post" action="recruiter.php?section=interviews" onsubmit="return confirm('Cancel this interview and notify the student?')">
                                        <input type="hidden" name="_csrf" value="<?= recruiter_h($csrf) ?>">
                                        <input type="hidden" name="action" value="cancel_interview">
                                        <input type="hidden" name="return_section" value="interviews">
                                        <input type="hidden" name="interview_id" value="<?= (int) $interview['id'] ?>">
                                        <button class="rec-button rec-button--quiet-danger rec-button--compact" type="submit">Cancel</button>
                                    </form>
                                    <?php if (new DateTimeImmutable((string) $interview['scheduled_at']) <= new DateTimeImmutable()): ?>
                                        <form method="post" action="recruiter.php?section=interviews">
                                            <input type="hidden" name="_csrf" value="<?= recruiter_h($csrf) ?>">
                                            <input type="hidden" name="action" value="complete_interview">
                                            <input type="hidden" name="return_section" value="interviews">
                                            <input type="hidden" name="interview_id" value="<?= (int) $interview['id'] ?>">
                                            <button class="rec-button rec-button--secondary rec-button--compact" type="submit">Mark complete</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
