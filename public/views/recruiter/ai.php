<?php
/**
 * Recruiter AI tools.
 *
 * Three read-only helpers. A drafted job description is text the recruiter
 * copies into the normal job form — this page never publishes anything. The
 * interview kit is a question bank with rubrics, and the resume comparison
 * quotes the applicant's own words against the requirements this recruiter
 * already set. No candidate is scored, ranked or rejected here.
 */
$aiTabs = [
    'jd' => 'Draft a role description',
    'kit' => 'Interview kit',
    'compare' => 'Resume match',
];
?>
<section class="rec-card" aria-labelledby="rec-ai-title">
    <div class="rec-section-heading">
        <div>
            <p class="rec-eyebrow">ASSISTED, NOT AUTOMATED</p>
            <h2 id="rec-ai-title">AI tools</h2>
            <p class="muted">Everything on this page runs on your own job data and the applicant's own resume. Nothing is published, ranked or decided automatically.</p>
        </div>
    </div>

    <nav class="rec-ai-tabs" aria-label="AI tools">
        <?php foreach ($aiTabs as $key => $label): ?>
            <a class="rec-button <?= $aiTool === $key ? 'rec-button--primary' : 'rec-button--secondary' ?>"
               href="recruiter.php?section=ai&amp;tool=<?= recruiter_h($key) ?>"
               <?= $aiTool === $key ? 'aria-current="page"' : '' ?>><?= recruiter_h($label) ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($companyId === 0): ?>
        <div class="rec-empty-inline">
            <span class="rec-empty-orb" aria-hidden="true"></span>
            <p>Submit your company profile first. The interview kit and resume comparison read your own jobs.</p>
        </div>
    <?php endif; ?>

    <?php if ($aiTool === 'jd'): ?>
        <form class="rec-form" method="post" action="recruiter.php?section=ai&amp;tool=jd">
            <input type="hidden" name="_csrf" value="<?= recruiter_h($csrf) ?>">
            <input type="hidden" name="ai_action" value="draft">
            <label for="ai-jd-text">Paste the role description in your own words
                <textarea id="ai-jd-text" name="jd_text" rows="7" maxlength="8000" required placeholder="Job title: Backend Engineer&#10;Location: Mohali, hybrid&#10;Employment type: Full-time&#10;Minimum CGPA: 7.0&#10;Eligible branch: CSE, IT&#10;Required skills: PHP, MySQL, Git&#10;Preferred skills: Docker&#10;Responsibilities:&#10;- Build and maintain internal APIs"></textarea>
            </label>
            <button class="rec-button rec-button--primary" type="submit">Extract the fields</button>
            <p class="rec-form-note">Fields are extracted with rules, not guessed: anything not found is listed as missing so you fill it in yourself.</p>
        </form>

        <?php if (is_array($aiDraft)): ?>
            <div class="rec-panel-grid" style="margin-top:20px">
                <article class="rec-card">
                    <h3>Extracted fields</h3>
                    <dl class="rec-key-values">
                        <dt>Title</dt><dd><?= $aiDraft['title'] !== '' ? recruiter_h($aiDraft['title']) : '<em>not found</em>' ?></dd>
                        <dt>Location</dt><dd><?= $aiDraft['location'] !== '' ? recruiter_h($aiDraft['location']) : '<em>not found</em>' ?></dd>
                        <dt>Employment type</dt><dd><?= $aiDraft['employment_type'] !== '' ? recruiter_h($aiDraft['employment_type']) : '<em>not found</em>' ?></dd>
                        <dt>Minimum CGPA</dt><dd><?= $aiDraft['min_cgpa'] === null ? '<em>not found</em>' : recruiter_h(number_format((float) $aiDraft['min_cgpa'], 2)) ?></dd>
                        <dt>Graduation year</dt><dd><?= $aiDraft['graduation_year'] === null ? '<em>not found</em>' : (int) $aiDraft['graduation_year'] ?></dd>
                        <dt>Eligible branch</dt><dd><?= $aiDraft['eligible_branch'] !== '' ? recruiter_h($aiDraft['eligible_branch']) : '<em>not found</em>' ?></dd>
                        <dt>Closes on</dt><dd><?= $aiDraft['closes_at'] !== '' ? recruiter_h($aiDraft['closes_at']) : '<em>not found</em>' ?></dd>
                    </dl>
                    <?php if ($aiDraft['missing'] !== []): ?>
                        <p class="rec-form-note"><strong>Still missing:</strong> <?= recruiter_h(implode(', ', $aiDraft['missing'])) ?></p>
                    <?php endif; ?>
                </article>
                <article class="rec-card">
                    <h3>Requirements and responsibilities</h3>
                    <p><strong>Required</strong></p>
                    <p class="rec-skill-chips">
                        <?php foreach ($aiDraft['required_skills'] as $skill): ?>
                            <span class="rec-skill-chip"><?= recruiter_h((string) $skill) ?></span>
                        <?php endforeach; ?>
                        <?= $aiDraft['required_skills'] === [] ? '<span class="muted">None recognised.</span>' : '' ?>
                    </p>
                    <p><strong>Preferred</strong></p>
                    <p class="rec-skill-chips">
                        <?php foreach ($aiDraft['preferred_skills'] as $skill): ?>
                            <span class="rec-skill-chip rec-skill-chip--preferred"><?= recruiter_h((string) $skill) ?></span>
                        <?php endforeach; ?>
                        <?= $aiDraft['preferred_skills'] === [] ? '<span class="muted">None recognised.</span>' : '' ?>
                    </p>
                    <?php if ($aiDraft['responsibilities'] !== []): ?>
                        <p><strong>Responsibilities found</strong></p>
                        <ul class="rec-list">
                            <?php foreach ($aiDraft['responsibilities'] as $line): ?>
                                <li><?= recruiter_h((string) $line) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </article>
                <article class="rec-card">
                    <h3>Description kept as you wrote it</h3>
                    <p class="muted">Paste this into the job form; the normal validation and T&amp;P approval still apply.</p>
                    <textarea rows="10" readonly aria-label="Drafted description"><?= recruiter_h((string) $aiDraft['description']) ?></textarea>
                    <p class="rec-form-note">This tool does not create a job. Use “Create job” to publish, which records who created it and keeps it in the approval flow.</p>
                </article>
            </div>
        <?php endif; ?>
    <?php elseif ($aiTool === 'kit'): ?>
        <form class="rec-form" method="post" action="recruiter.php?section=ai&amp;tool=kit">
            <input type="hidden" name="_csrf" value="<?= recruiter_h($csrf) ?>">
            <input type="hidden" name="ai_action" value="kit">
            <label for="ai-kit-job">Job
                <select id="ai-kit-job" name="job" required>
                    <option value="">Choose one of your jobs</option>
                    <?php foreach ($aiJobs as $job): ?>
                        <option value="<?= (int) $job['id'] ?>" <?= (int) $job['id'] === $aiJobId ? 'selected' : '' ?>>
                            <?= recruiter_h((string) $job['title']) ?> (<?= recruiter_h((string) $job['status']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="rec-button rec-button--primary" type="submit">Build the kit</button>
            <p class="rec-form-note">Questions come from that job's required and preferred skill lists, so the kit matches what you published.</p>
        </form>

        <?php if (is_array($aiKit)): ?>
            <?php if ($aiKit === []): ?>
                <div class="rec-empty-inline"><p>That job has no skills listed yet, so no questions could be generated. Add requirements to the job first.</p></div>
            <?php else: ?>
                <div class="rec-panel-grid" style="margin-top:20px">
                    <?php foreach ($aiKit as $question): ?>
                        <article class="rec-card">
                            <p class="rec-eyebrow"><?= recruiter_h(ucfirst((string) $question['type'])) ?><?= $question['skill'] !== '' ? ' · ' . recruiter_h((string) $question['skill']) : '' ?></p>
                            <h3><?= recruiter_h((string) $question['prompt']) ?></h3>
                            <ul class="rec-list">
                                <?php foreach ($question['rubric'] as $line): ?>
                                    <li><?= recruiter_h((string) $line) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </article>
                    <?php endforeach; ?>
                </div>
                <p class="rec-form-note">A rubric gives every candidate the same scale. It is not a score of personality, and it should never replace your own judgement.</p>
            <?php endif; ?>
        <?php endif; ?>
    <?php else: ?>
        <form class="rec-form" method="post" action="recruiter.php?section=ai&amp;tool=compare">
            <input type="hidden" name="_csrf" value="<?= recruiter_h($csrf) ?>">
            <input type="hidden" name="ai_action" value="compare">
            <label for="ai-compare-applicant">Applicant
                <select id="ai-compare-applicant" name="application_id" required>
                    <option value="">Choose an applicant with a resume</option>
                    <?php foreach ($aiApplicants as $applicant): ?>
                        <?php if ((int) ($applicant['resume_id'] ?? 0) === 0) { continue; } ?>
                        <option value="<?= (int) $applicant['id'] ?>">
                            <?= recruiter_h((string) $applicant['student_name']) ?> · <?= recruiter_h((string) $applicant['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="rec-button rec-button--primary" type="submit">Compare with the requirements</button>
            <p class="rec-form-note">Only applicants to your own jobs appear here, and the read is recorded in the audit log.</p>
        </form>

        <?php if ($aiNotice !== ''): ?>
            <div class="rec-empty-inline" role="status"><p><?= recruiter_h($aiNotice) ?></p></div>
        <?php endif; ?>

        <?php if (is_array($aiCompare)): ?>
            <div class="rec-panel-grid" style="margin-top:20px">
                <article class="rec-card">
                    <h3><?= recruiter_h((string) $aiCompare['applicant']['student_name']) ?></h3>
                    <p class="muted"><?= recruiter_h((string) $aiCompare['applicant']['title']) ?> · <?= (int) $aiCompare['pages'] ?> page(s) read · <?= recruiter_h((string) $aiCompare['summary']) ?></p>
                    <p><strong>Mentioned in the resume</strong></p>
                    <p class="rec-skill-chips">
                        <?php foreach ($aiCompare['matched'] as $skill): ?>
                            <span class="rec-skill-chip"><?= recruiter_h((string) $skill) ?></span>
                        <?php endforeach; ?>
                        <?= $aiCompare['matched'] === [] ? '<span class="muted">None of the required skills appear.</span>' : '' ?>
                    </p>
                    <p><strong>Not found in the text</strong></p>
                    <p class="rec-skill-chips">
                        <?php foreach ($aiCompare['missing'] as $skill): ?>
                            <span class="rec-skill-chip rec-skill-chip--preferred"><?= recruiter_h((string) $skill) ?></span>
                        <?php endforeach; ?>
                        <?= $aiCompare['missing'] === [] ? '<span class="muted">Every required skill appears.</span>' : '' ?>
                    </p>
                </article>
                <article class="rec-card">
                    <h3>Where each skill appears</h3>
                    <?php if ($aiCompare['evidence'] === []): ?>
                        <p class="muted">No quoted lines to show.</p>
                    <?php else: ?>
                        <ul class="rec-list">
                            <?php foreach ($aiCompare['evidence'] as $skill => $line): ?>
                                <li><strong><?= recruiter_h((string) $skill) ?></strong> — <?= recruiter_h((string) $line) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <p class="rec-form-note">A missing word is not a missing skill: the applicant may have the experience under different wording. Read the resume before deciding, and never use this list as an automatic filter.</p>
                </article>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <p class="rec-form-note" style="margin-top:18px">These tools are deterministic. If an AI provider is configured on the server, it may rephrase text from the same facts, but it never decides who is hired.</p>
</section>
