<div class="rec-section-layout">
    <section class="rec-card rec-card--form" aria-labelledby="rec-company-form-title">
        <div class="rec-section-heading">
            <div>
                <p class="rec-eyebrow">ORGANIZATION DETAILS</p>
                <h2 id="rec-company-form-title"><?= $companyId ? 'Company profile' : 'Set up your company profile' ?></h2>
            </div>
            <span class="rec-company-state rec-company-state--<?= recruiter_h(str_replace('_', '-', $companyStatus)) ?>">
                <span class="rec-status-dot" aria-hidden="true"></span><?= recruiter_h($companyStatusLabel) ?>
            </span>
        </div>

        <?php if ($companyStatus === 'approved'): ?>
            <p class="rec-form-intro">Your company is approved. Changes to the organization name, website, industry, or description will be sent to T&amp;P for another review.</p>
        <?php elseif ($companyStatus === 'pending'): ?>
            <p class="rec-form-intro">Your profile is awaiting review. You can update details here; any saved changes keep the profile in the review queue.</p>
        <?php elseif ($companyStatus === 'rejected'): ?>
            <p class="rec-form-intro">Review the requested changes, update the profile, and submit it again to the Training &amp; Placement Cell.</p>
        <?php else: ?>
            <p class="rec-form-intro">Share accurate organization details so the SVIET Training &amp; Placement Cell can review your company before you publish roles.</p>
        <?php endif; ?>

        <form class="rec-form" method="post" action="recruiter.php?section=company">
            <input type="hidden" name="_csrf" value="<?= recruiter_h($csrf) ?>">
            <input type="hidden" name="action" value="save_company">
            <input type="hidden" name="return_section" value="company">

            <div class="rec-form-grid">
                <label for="company-name">
                    Company name <span aria-hidden="true">*</span>
                    <input id="company-name" type="text" name="name" required maxlength="180" autocomplete="organization" value="<?= recruiter_h((string) ($company['company_name'] ?? '')) ?>">
                </label>
                <label for="company-website">
                    Website
                    <input id="company-website" type="url" name="website" maxlength="255" inputmode="url" placeholder="https://example.com" value="<?= recruiter_h((string) ($company['website'] ?? '')) ?>">
                </label>
                <label for="company-industry">
                    Industry
                    <input id="company-industry" type="text" name="industry" maxlength="120" placeholder="e.g. Engineering, Software" value="<?= recruiter_h((string) ($company['industry'] ?? '')) ?>">
                </label>
                <label for="recruiter-designation">
                    Your designation
                    <input id="recruiter-designation" type="text" name="designation" maxlength="120" autocomplete="organization-title" placeholder="e.g. Talent Acquisition Lead" value="<?= recruiter_h((string) ($company['designation'] ?? '')) ?>">
                </label>
                <label class="rec-form-grid__wide" for="company-description">
                    Company description
                    <textarea id="company-description" name="description" rows="5" maxlength="4000" aria-describedby="company-description-help" placeholder="A concise overview of your organization and the work students would do."><?= recruiter_h((string) ($company['description'] ?? '')) ?></textarea>
                    <small id="company-description-help">Up to 4,000 characters. Avoid adding confidential information.</small>
                </label>
            </div>

            <div class="rec-form-actions">
                <button class="rec-button rec-button--primary" type="submit">
                    <?= $companyId ? 'Save company profile' : 'Submit for review' ?> <span aria-hidden="true">↗</span>
                </button>
                <span class="rec-form-note">Company profile details are reviewed by authorized SVIET staff.</span>
            </div>
        </form>
    </section>

    <aside class="rec-card rec-review-card" aria-labelledby="rec-review-title">
        <p class="rec-eyebrow">REVIEW FLOW</p>
        <h2 id="rec-review-title">What happens next?</h2>
        <ol class="rec-steps">
            <li class="<?= $companyId ? 'is-complete' : 'is-current' ?>">
                <span>01</span><div><strong>Submit organization details</strong><small>Provide accurate company information and a working website.</small></div>
            </li>
            <li class="<?= $companyStatus === 'approved' ? 'is-complete' : ($companyId ? 'is-current' : '') ?>">
                <span>02</span><div><strong>T&amp;P reviews your profile</strong><small>The status above shows whether review is pending, approved, or needs changes.</small></div>
            </li>
            <li class="<?= $companyStatus === 'approved' ? 'is-current' : '' ?>">
                <span>03</span><div><strong>Publish and manage roles</strong><small>Approved companies can publish directly. T&amp;P may pause or close a listing.</small></div>
            </li>
        </ol>
        <?php if ($companyStatus === 'pending' || $companyStatus === 'rejected'): ?>
            <div class="rec-note rec-note--soft">
                <strong><?= $companyStatus === 'pending' ? 'While review is pending' : 'After you resubmit' ?></strong>
                <p>You can prepare draft jobs. Published roles are visible only while the company profile is approved.</p>
            </div>
        <?php endif; ?>
    </aside>
</div>
