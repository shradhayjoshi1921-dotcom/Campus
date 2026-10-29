<?php
$canPublish = $companyStatus === 'approved';
$isCreating = recruiter_get('mode') === 'create';
$jobFormOpen = $editJob !== null || $isCreating || $jobTotal === 0;
$currentJobStatus = (string) ($editJob['status'] ?? 'draft');
?>

<?php if ($editJobId > 0 && !$editJob): ?>
    <div class="rec-alert rec-alert--info" role="status">That role could not be found in your company workspace. Choose one of your roles to edit.</div>
<?php endif; ?>

<?php if (!$companyId): ?>
    <div class="rec-alert rec-alert--info">
        Complete your company profile before creating jobs. <a href="recruiter.php?section=company">Open company profile <span aria-hidden="true">↗</span></a>
    </div>
<?php elseif (!$canPublish): ?>
    <div class="rec-alert rec-alert--info">
        Your company is <?= recruiter_h(strtolower($companyStatusLabel)) ?>. You can prepare drafts now; publishing unlocks when the Training &amp; Placement Cell approves your company.
    </div>
<?php else: ?>
    <div class="rec-alert rec-alert--success rec-alert--subtle">
        Approved companies can publish roles directly. The Training &amp; Placement Cell may pause or close a listing if details need attention.
    </div>
<?php endif; ?>

<div class="rec-jobs-layout">
    <section class="rec-card rec-card--jobs-list" aria-labelledby="rec-jobs-list-title">
        <div class="rec-section-heading">
            <div>
                <p class="rec-eyebrow">ROLE LIBRARY · <?= (int) $jobTotal ?> MATCHES</p>
                <h2 id="rec-jobs-list-title">Your roles</h2>
            </div>
            <a class="rec-button rec-button--secondary rec-button--compact" href="recruiter.php?section=jobs&mode=create">＋ New role</a>
        </div>

        <form class="rec-filter-bar" method="get" action="recruiter.php">
            <input type="hidden" name="section" value="jobs">
            <label class="rec-search-field" for="job-search">
                <span class="sr-only">Search jobs</span>
                <svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.8"/><path d="m16 16 5 5"/></svg>
                <input id="job-search" name="q" type="search" maxlength="80" placeholder="Search job title or location" value="<?= recruiter_h($jobSearch) ?>">
            </label>
            <label class="sr-only" for="job-status-filter">Filter jobs by status</label>
            <select id="job-status-filter" name="status">
                <?php foreach (['all' => 'All statuses', 'draft' => 'Drafts', 'published' => 'Published', 'paused' => 'Paused', 'closed' => 'Closed'] as $value => $label): ?>
                    <option value="<?= recruiter_h($value) ?>" <?= $jobStatusFilter === $value ? 'selected' : '' ?>><?= recruiter_h($label) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="rec-button rec-button--secondary rec-button--compact" type="submit">Filter</button>
        </form>

        <?php if (!$jobs): ?>
            <div class="rec-empty-state">
                <span class="rec-empty-orb" aria-hidden="true"></span>
                <h3><?= $jobSearch !== '' || $jobStatusFilter !== 'all' ? 'No matching roles' : 'No roles yet' ?></h3>
                <p><?= $jobSearch !== '' || $jobStatusFilter !== 'all' ? 'Try another search or status filter.' : 'Create a draft role to get started. You can publish once your company is approved.' ?></p>
                <?php if ($jobSearch === '' && $jobStatusFilter === 'all' && $companyId): ?>
                    <a class="rec-button rec-button--primary" href="recruiter.php?section=jobs&mode=create">Create your first role <span aria-hidden="true">↗</span></a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="rec-job-list">
                <?php foreach ($jobs as $job): ?>
                    <article class="rec-job-card">
                        <div class="rec-job-main">
                            <div class="rec-job-title-row">
                                <div>
                                    <p class="rec-job-company"><?= recruiter_h((string) ($company['company_name'] ?? 'Company')) ?></p>
                                    <h3><?= recruiter_h($job['title']) ?></h3>
                                </div>
                                <span class="rec-status-pill rec-status-pill--<?= recruiter_h($job['status']) ?>"><?= recruiter_h(recruiter_status_label($job['status'])) ?></span>
                            </div>
                            <div class="rec-job-meta">
                                <span><?= recruiter_h((string) ($job['location'] ?: 'Location not specified')) ?></span>
                                <span><?= recruiter_h((string) ($job['employment_type'] ?: 'Employment type not specified')) ?></span>
                                <span><?= (int) $job['applicant_count'] ?> applicants</span>
                                <span>Min CGPA <?= recruiter_h((string) $job['min_cgpa']) ?></span>
                            </div>
                            <?php $jobSkills = $jobSkillMap[(int) $job['id']] ?? []; ?>
                            <?php if (!empty($jobSkills['required']) || !empty($jobSkills['preferred'])): ?>
                                <p class="rec-skill-chips">
                                    <?php foreach (($jobSkills['required'] ?? []) as $skillName): ?>
                                        <span class="rec-skill-chip"><?= recruiter_h((string) $skillName) ?></span>
                                    <?php endforeach; ?>
                                    <?php foreach (($jobSkills['preferred'] ?? []) as $skillName): ?>
                                        <span class="rec-skill-chip rec-skill-chip--preferred"><?= recruiter_h((string) $skillName) ?><span class="sr-only"> (preferred)</span></span>
                                    <?php endforeach; ?>
                                </p>
                            <?php else: ?>
                                <p class="rec-job-note">No skills listed yet. Students see an academic-only fit score for this role until you add required skills.</p>
                            <?php endif; ?>
                            <?php if (!$canPublish && $job['status'] === 'published'): ?>
                                <p class="rec-job-note">This listing is hidden from students while the company profile is under review.</p>
                            <?php endif; ?>
                        </div>
                        <a class="rec-icon-action" href="recruiter.php?section=jobs&edit=<?= (int) $job['id'] ?>" aria-label="Edit <?= recruiter_h($job['title']) ?>">
                            <svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L8 18l-4 1 1-4Z"/></svg>
                            <span>Edit</span>
                        </a>
                    </article>
                <?php endforeach; ?>
            </div>

            <?php if ($jobPages > 1): ?>
                <nav class="rec-pagination" aria-label="Job list pages">
                    <?php if ($jobPage > 1): ?>
                        <a href="recruiter.php?section=jobs&status=<?= recruiter_h($jobStatusFilter) ?>&q=<?= rawurlencode($jobSearch) ?>&job_page=<?= $jobPage - 1 ?>">Previous</a>
                    <?php endif; ?>
                    <span>Page <?= (int) $jobPage ?> of <?= (int) $jobPages ?></span>
                    <?php if ($jobPage < $jobPages): ?>
                        <a href="recruiter.php?section=jobs&status=<?= recruiter_h($jobStatusFilter) ?>&q=<?= rawurlencode($jobSearch) ?>&job_page=<?= $jobPage + 1 ?>">Next</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <section class="rec-card rec-card--job-form" aria-labelledby="rec-job-form-title">
        <div class="rec-section-heading">
            <div>
                <p class="rec-eyebrow"><?= $editJob ? 'EDIT ROLE' : 'NEW OPPORTUNITY' ?></p>
                <h2 id="rec-job-form-title"><?= $editJob ? 'Role details' : 'Create a role' ?></h2>
            </div>
            <?php if ($editJob): ?>
                <a class="rec-text-link" href="recruiter.php?section=jobs">Cancel edit</a>
            <?php endif; ?>
        </div>

        <?php if (!$companyId): ?>
            <p class="rec-form-intro">Submit your company profile before preparing job roles.</p>
        <?php else: ?>
            <?php if (!$editJob && !$jobFormOpen): ?>
                <div class="rec-form-placeholder">
                    <span class="rec-empty-orb" aria-hidden="true"></span>
                    <p>Keep the roles list focused. Start a new role when you’re ready.</p>
                    <a class="rec-button rec-button--primary" href="recruiter.php?section=jobs&mode=create">Open role form <span aria-hidden="true">↗</span></a>
                </div>
            <?php else: ?>
                <form class="rec-form" method="post" action="recruiter.php?section=jobs">
                    <input type="hidden" name="_csrf" value="<?= recruiter_h($csrf) ?>">
                    <input type="hidden" name="action" value="save_job">
                    <input type="hidden" name="return_section" value="jobs">
                    <input type="hidden" name="job_id" value="<?= (int) ($editJob['id'] ?? 0) ?>">

                    <label for="job-title">Job title <span aria-hidden="true">*</span>
                        <input id="job-title" name="title" required maxlength="180" value="<?= recruiter_h((string) ($editJob['title'] ?? '')) ?>" placeholder="e.g. Graduate Software Engineer">
                    </label>
                    <div class="rec-form-grid">
                        <label for="job-location">Location
                            <input id="job-location" name="location" maxlength="180" value="<?= recruiter_h((string) ($editJob['location'] ?? '')) ?>" placeholder="City or remote">
                        </label>
                        <label for="job-type">Employment type
                            <input id="job-type" name="employment_type" maxlength="80" value="<?= recruiter_h((string) ($editJob['employment_type'] ?? '')) ?>" placeholder="Full-time / Internship">
                        </label>
                        <label for="job-cgpa">Minimum CGPA <span aria-hidden="true">*</span>
                            <input id="job-cgpa" type="number" name="min_cgpa" min="0" max="10" step="0.01" required value="<?= recruiter_h((string) ($editJob['min_cgpa'] ?? '0')) ?>">
                        </label>
                        <label for="job-branch">Eligible branch
                            <input id="job-branch" name="eligible_branch" maxlength="120" value="<?= recruiter_h((string) ($editJob['eligible_branch'] ?? '')) ?>" placeholder="Leave blank for all branches">
                        </label>
                        <label for="job-year">Graduation year
                            <input id="job-year" type="number" name="graduation_year" min="2000" max="2150" value="<?= recruiter_h((string) ($editJob['graduation_year'] ?? '')) ?>" placeholder="e.g. 2027">
                        </label>
                        <label for="job-closes">Application deadline (IST)
                            <input id="job-closes" type="datetime-local" name="closes_at" value="<?= recruiter_h($editJob && $editJob['closes_at'] ? date('Y-m-d\TH:i', strtotime($editJob['closes_at'])) : '') ?>">
                        </label>
                    </div>
                    <label for="job-description">Role description <span aria-hidden="true">*</span>
                        <textarea id="job-description" name="description" rows="6" maxlength="12000" required placeholder="Responsibilities, required skills, and what candidates can expect."><?= recruiter_h((string) ($editJob['description'] ?? '')) ?></textarea>
                    </label>
                    <div class="rec-form-grid">
                        <label for="job-required-skills">Required skills
                            <input id="job-required-skills" name="required_skills" maxlength="600"
                                   value="<?= recruiter_h(implode(', ', $editJobSkills['required'] ?? [])) ?>"
                                   placeholder="e.g. PHP, SQL, Git">
                            <small>Comma separated. These drive every student's fit score, skill gap and learning roadmap.</small>
                        </label>
                        <label for="job-preferred-skills">Preferred skills
                            <input id="job-preferred-skills" name="preferred_skills" maxlength="600"
                                   value="<?= recruiter_h(implode(', ', $editJobSkills['preferred'] ?? [])) ?>"
                                   placeholder="e.g. Docker, AWS">
                            <small>Nice to have. A skill listed as required is never duplicated here.</small>
                        </label>
                    </div>
                    <?php if ($knownSkills): ?>
                        <p class="rec-form-hint">
                            Already used on the portal:
                            <span><?= recruiter_h(implode(', ', array_slice($knownSkills, 0, 12))) ?></span><?= count($knownSkills) > 12 ? '…' : '' ?>
                            Reuse these spellings so students' skills match.
                        </p>
                    <?php endif; ?>
                    <label for="job-status">Listing status
                        <select id="job-status" name="status" required>
                            <?php if ($canPublish): ?>
                                <option value="published" <?= $currentJobStatus === 'published' ? 'selected' : '' ?>>Published</option>
                            <?php elseif ($currentJobStatus === 'published'): ?>
                                <option value="" selected disabled>Published · locked until approval</option>
                            <?php endif; ?>
                            <option value="draft" <?= $currentJobStatus === 'draft' ? 'selected' : '' ?>>Draft</option>
                            <option value="paused" <?= $currentJobStatus === 'paused' ? 'selected' : '' ?>>Paused</option>
                            <option value="closed" <?= $currentJobStatus === 'closed' ? 'selected' : '' ?>>Closed</option>
                        </select>
                        <small><?= $canPublish ? 'Approved companies can publish directly; T&P may moderate listings.' : 'Drafts are allowed while your company profile is under review. Publishing is locked until approval.' ?></small>
                    </label>
                    <div class="rec-form-actions">
                        <button class="rec-button rec-button--primary" type="submit">
                            <?= $editJob ? 'Save role changes' : 'Save role' ?> <span aria-hidden="true">↗</span>
                        </button>
                        <?php if (!$canPublish): ?>
                            <span class="rec-form-note">Only approved company profiles can publish roles to students.</span>
                        <?php endif; ?>
                    </div>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</div>
