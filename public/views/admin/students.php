<section class="admin-card" aria-labelledby="admin-import-title">
    <div class="admin-section-heading">
        <div>
            <p class="admin-eyebrow">ONBOARDING</p>
            <h2 id="admin-import-title">Import a batch</h2>
        </div>
        <span class="admin-pill">Up to <?= \App\Services\AdminImportService::MAX_ROWS ?> rows · 512 KB</span>
    </div>
    <p class="admin-muted">
        Upload a CSV to create student or recruiter accounts in one pass. Nothing is written until you review the
        preview: every row is validated first and invalid rows are skipped, never half-imported.
    </p>

    <form class="admin-import-form" method="post" enctype="multipart/form-data">
        <input type="hidden" name="_csrf" value="<?= admin_h($csrf) ?>">
        <input type="hidden" name="action" value="import_preview">
        <input type="hidden" name="return_section" value="students">
        <label class="admin-file">
            <span class="sr-only">CSV file</span>
            <input type="file" name="csv" accept=".csv,text/csv" required>
        </label>
        <button class="admin-button" type="submit">Validate file</button>
    </form>

    <details class="admin-details">
        <summary>CSV format and a copyable template</summary>
        <p class="admin-muted admin-small">
            Required: <code>name</code>, <code>email</code>, <code>role</code> (<code>student</code> or <code>recruiter</code>).
            Optional: <code>branch</code>, <code>cgpa</code>, <code>graduation_year</code>, <code>company</code>, <code>designation</code>.
            <code>company</code> is required for recruiters and unknown companies are created as pending review.
        </p>
        <pre class="admin-pre"><?= admin_h((string) ($importTemplate ?? '')) ?></pre>
    </details>

    <?php if (is_array($importPreview)): ?>
        <?php
        $previewRows = (array) $importPreview['rows'];
        $validRows = array_values(array_filter($previewRows, static fn (array $row): bool => !empty($row['valid'])));
        ?>
        <div class="admin-preview">
            <div class="admin-section-heading">
                <div>
                    <h3>Preview · <?= admin_h((string) ($importPreview['file'] ?? 'upload.csv')) ?></h3>
                    <p class="admin-muted admin-small"><?= count($validRows) ?> ready · <?= count($previewRows) - count($validRows) ?> skipped</p>
                </div>
            </div>
            <div class="admin-table-wrap">
                <table class="admin-table admin-table--compact">
                    <thead>
                        <tr>
                            <th scope="col">Line</th>
                            <th scope="col">Name</th>
                            <th scope="col">Email</th>
                            <th scope="col">Role</th>
                            <th scope="col">Details</th>
                            <th scope="col">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($previewRows as $row): ?>
                            <tr class="<?= empty($row['valid']) ? 'is-invalid' : '' ?>">
                                <td><?= (int) $row['line'] ?></td>
                                <td><?= admin_h((string) ($row['values']['name'] ?? '')) ?></td>
                                <td><?= admin_h((string) ($row['values']['email'] ?? '')) ?></td>
                                <td><?= admin_h((string) ($row['values']['role'] ?? '')) ?></td>
                                <td>
                                    <?php
                                    $details = [];
                                    if (($row['values']['branch'] ?? '') !== '') {
                                        $details[] = $row['values']['branch'];
                                    }
                                    if (($row['values']['cgpa'] ?? '') !== '') {
                                        $details[] = 'CGPA ' . $row['values']['cgpa'];
                                    }
                                    if (($row['values']['graduation_year'] ?? '') !== '') {
                                        $details[] = 'Class of ' . $row['values']['graduation_year'];
                                    }
                                    if (($row['values']['company'] ?? '') !== '') {
                                        $details[] = $row['values']['company'];
                                    }
                                    ?>
                                    <?= admin_h($details ? implode(' · ', $details) : '—') ?>
                                </td>
                                <td>
                                    <?php if (!empty($row['valid'])): ?>
                                        <span class="admin-pill admin-pill--ok">Ready</span>
                                    <?php else: ?>
                                        <span class="admin-pill admin-pill--bad">Skipped</span>
                                        <span class="admin-cell-sub"><?= admin_h(implode(' ', (array) $row['errors'])) ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="admin-preview-actions">
                <form method="post">
                    <input type="hidden" name="_csrf" value="<?= admin_h($csrf) ?>">
                    <input type="hidden" name="action" value="import_commit">
                    <input type="hidden" name="return_section" value="students">
                    <button class="admin-button" type="submit" <?= $validRows === [] ? 'disabled' : '' ?>>
                        Create <?= count($validRows) ?> account<?= count($validRows) === 1 ? '' : 's' ?>
                    </button>
                </form>
                <form method="post">
                    <input type="hidden" name="_csrf" value="<?= admin_h($csrf) ?>">
                    <input type="hidden" name="action" value="import_cancel">
                    <input type="hidden" name="return_section" value="students">
                    <button class="admin-button admin-button--quiet" type="submit">Cancel</button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($importCredentials !== []): ?>
        <?php
        $credentialCsv = \App\Services\AdminImportService::credentialsCsv($importCredentials);
        $credentialHref = 'data:text/csv;charset=utf-8,' . rawurlencode($credentialCsv);
        ?>
        <div class="admin-preview admin-preview--once" role="status">
            <div class="admin-section-heading">
                <div>
                    <h3>Credential sheet · shown once</h3>
                    <p class="admin-muted admin-small">
                        There is no mailer in this project. Download this sheet, give each person their temporary password
                        through a secure channel, and ask them to change it after signing in.
                    </p>
                </div>
                <a class="admin-button admin-button--small" href="<?= admin_h($credentialHref) ?>" download="sviet-temporary-credentials.csv">
                    Download CSV
                </a>
            </div>
            <div class="admin-table-wrap">
                <table class="admin-table admin-table--compact">
                    <thead>
                        <tr><th scope="col">Name</th><th scope="col">Email</th><th scope="col">Role</th><th scope="col">Temporary password</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($importCredentials as $account): ?>
                            <tr>
                                <td><?= admin_h((string) $account['name']) ?></td>
                                <td><?= admin_h((string) $account['email']) ?></td>
                                <td><?= admin_h((string) $account['role']) ?></td>
                                <td><code><?= admin_h((string) $account['password']) ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($importSkipped !== []): ?>
                <details class="admin-details">
                    <summary><?= count($importSkipped) ?> row(s) skipped</summary>
                    <ul class="admin-list">
                        <?php foreach ($importSkipped as $line): ?>
                            <li><?= admin_h((string) $line) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>

<section class="admin-card" aria-labelledby="admin-health-title">
    <div class="admin-section-heading">
        <div>
            <p class="admin-eyebrow">DATA HEALTH</p>
            <h2 id="admin-health-title">Profile completeness</h2>
        </div>
        <form method="post" onsubmit="return confirm('Send a reminder to every student with an incomplete profile?');">
            <input type="hidden" name="_csrf" value="<?= admin_h($csrf) ?>">
            <input type="hidden" name="action" value="nudge_profiles">
            <input type="hidden" name="return_section" value="students">
            <button class="admin-button admin-button--quiet admin-button--small" type="submit" <?= $dataHealth['incomplete'] === 0 ? 'disabled' : '' ?>>
                Send reminder
            </button>
        </form>
    </div>
    <dl class="admin-metrics admin-metrics--four">
        <div><dt>Missing branch</dt><dd><?= (int) $dataHealth['no_branch'] ?></dd></div>
        <div><dt>Missing CGPA</dt><dd><?= (int) $dataHealth['no_cgpa'] ?></dd></div>
        <div><dt>Missing graduation year</dt><dd><?= (int) $dataHealth['no_year'] ?></dd></div>
        <div><dt>Gaps in total</dt><dd><?= (int) $dataHealth['incomplete'] ?></dd></div>
    </dl>
</section>

<section class="admin-card" aria-labelledby="admin-students-title">
    <div class="admin-section-heading">
        <div>
            <p class="admin-eyebrow">ROSTER</p>
            <h2 id="admin-students-title">Students</h2>
        </div>
        <a class="admin-text-link" href="admin.php?section=reports&export=students">Export CSV</a>
    </div>
    <?php if (!$students): ?>
        <p class="admin-empty">No student accounts yet. Import a batch to get started.</p>
    <?php else: ?>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th scope="col">Student</th>
                        <th scope="col">Branch</th>
                        <th scope="col">CGPA</th>
                        <th scope="col">Class of</th>
                        <th scope="col">Applications</th>
                        <th scope="col">Selected</th>
                        <th scope="col">Offers</th>
                        <th scope="col">Account</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $student): ?>
                        <tr>
                            <td>
                                <strong><?= admin_h($student['name']) ?></strong>
                                <span class="admin-cell-sub"><?= admin_h($student['email']) ?></span>
                            </td>
                            <td><?= admin_h($student['branch'] ?: '—') ?></td>
                            <td><?= admin_h($student['cgpa'] !== null ? (string) $student['cgpa'] : '—') ?></td>
                            <td><?= admin_h($student['graduation_year'] !== null ? (string) $student['graduation_year'] : '—') ?></td>
                            <td><?= (int) $student['applications'] ?></td>
                            <td><?= (int) $student['selections'] ?></td>
                            <td><?= $offersReady ? (int) $student['offers'] : '—' ?></td>
                            <td>
                                <span class="admin-pill <?= $student['status'] === 'active' ? 'admin-pill--ok' : ($student['status'] === 'suspended' ? 'admin-pill--bad' : '') ?>">
                                    <?= admin_h(admin_status_label((string) $student['status'])) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
