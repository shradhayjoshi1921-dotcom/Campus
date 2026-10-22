<?php
/**
 * TPO / admin intelligence.
 *
 * Aggregates over the records Campus already holds. Two rules are visible in
 * the markup itself: no student is named, and every flag says it needs a human
 * to review it. There is no forecast, no ranking of students and no automatic
 * consequence.
 */
$report = is_array($institutionReport ?? null) ? $institutionReport : null;
$metric = static function (?float $value): string {
    return $value === null ? '—' : number_format($value, 1) . '%';
};
?>
<?php if ($report === null): ?>
    <section class="admin-card">
        <div class="admin-empty">
            <h2>Intelligence could not be built</h2>
            <p>The report needs the students, skills, jobs and application tables. Check the database connection, then refresh.</p>
        </div>
    </section>
<?php else: ?>
    <section class="admin-card" aria-labelledby="admin-intel-overview">
        <div class="admin-section-heading">
            <div>
                <p class="admin-eyebrow">COHORT</p>
                <h2 id="admin-intel-overview">What the data says about this cohort</h2>
                <p class="admin-muted"><?= admin_h((string) $report['notice']) ?></p>
            </div>
            <span class="admin-pill"><?= (int) $report['measured'] ?> of <?= (int) $report['students'] ?> students measurable</span>
        </div>
        <dl class="admin-metrics admin-metrics--four">
            <div><dt>Active students</dt><dd><?= (int) $report['students'] ?></dd></div>
            <div><dt>With readiness score</dt><dd><?= (int) $report['measured'] ?></dd></div>
            <div><dt>Open requirements tracked</dt><dd><?= count($report['skill_demand']['skills'] ?? []) ?></dd></div>
            <div><dt>Flags to review</dt><dd><?= count($report['anomalies']) ?></dd></div>
        </dl>
    </section>

    <section class="admin-card" aria-labelledby="admin-intel-readiness">
        <div class="admin-section-heading">
            <div>
                <p class="admin-eyebrow">READINESS</p>
                <h2 id="admin-intel-readiness">Preparation by branch</h2>
                <p class="admin-muted">Same six-category model students see in their career studio. It measures preparation, not the chance of being placed.</p>
            </div>
        </div>
        <?php if ($report['readiness_map'] === []): ?>
            <div class="admin-empty"><p>No student profiles yet, so there is nothing to average.</p></div>
        <?php else: ?>
            <div class="admin-table-wrap">
                <table class="admin-table admin-table--compact">
                    <caption class="admin-small">Average readiness by branch — branches with no measured student show a dash, never a zero.</caption>
                    <thead>
                        <tr><th scope="col">Branch</th><th scope="col">Students</th><th scope="col">Measured</th><th scope="col">Average</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report['readiness_map'] as $row): ?>
                            <tr>
                                <td><?= admin_h((string) $row['branch']) ?></td>
                                <td><?= (int) $row['students'] ?></td>
                                <td><?= (int) $row['measured'] ?></td>
                                <td><?= $row['average'] === null ? '—' : admin_h(number_format((float) $row['average'], 1)) . '%' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <div class="admin-split">
        <section class="admin-card" aria-labelledby="admin-intel-demand">
            <div class="admin-section-heading">
                <div>
                    <p class="admin-eyebrow">DEMAND</p>
                    <h2 id="admin-intel-demand">Skills employers actually ask for</h2>
                    <p class="admin-muted">Counted from published roles of approved companies only.</p>
                </div>
            </div>
            <?php if (($report['skill_demand']['skills'] ?? []) === []): ?>
                <div class="admin-empty"><p>No published role lists skills yet.</p></div>
            <?php else: ?>
                <div class="admin-table-wrap">
                    <table class="admin-table admin-table--compact">
                        <thead><tr><th scope="col">Skill</th><th scope="col">Required by</th><th scope="col">Preferred by</th></tr></thead>
                        <tbody>
                            <?php foreach (array_slice($report['skill_demand']['skills'], 0, 15) as $row): ?>
                                <tr>
                                    <td><?= admin_h((string) $row['skill']) ?></td>
                                    <td><?= (int) $row['required'] ?> role(s)</td>
                                    <td><?= (int) $row['preferred'] ?> role(s)</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (($report['skill_demand']['combinations'] ?? []) !== []): ?>
                    <p class="admin-small">Frequently requested together:
                        <?= admin_h(implode(' · ', array_map(static fn (array $pair): string => $pair['pair'] . ' (' . $pair['jobs'] . ')', array_slice($report['skill_demand']['combinations'], 0, 4)))) ?>
                    </p>
                <?php endif; ?>
                <p class="admin-small"><?= (int) ($report['skill_demand']['jobs_analysed'] ?? 0) ?> role(s) analysed across approved companies.</p>
            <?php endif; ?>
        </section>

        <section class="admin-card" aria-labelledby="admin-intel-gaps">
            <div class="admin-section-heading">
                <div>
                    <p class="admin-eyebrow">TRAINING</p>
                    <h2 id="admin-intel-gaps">Where to run the next workshop</h2>
                    <p class="admin-muted">Ranked by how many students are missing a skill that open roles require.</p>
                </div>
            </div>
            <?php if ($report['training_gaps'] === []): ?>
                <div class="admin-empty"><p>No required skill is missing across the cohort, or no requirements are recorded.</p></div>
            <?php else: ?>
                <ul class="admin-list">
                    <?php foreach ($report['training_gaps'] as $gap): ?>
                        <li>
                            <strong><?= admin_h((string) $gap['skill']) ?></strong>
                            <span class="admin-pill admin-pill--<?= $gap['priority'] === 'High' ? 'warn' : 'ok' ?>"><?= admin_h((string) $gap['priority']) ?></span>
                            <p class="admin-small"><?= (int) $gap['students'] ?> student(s) missing · requested by <?= (int) $gap['jobs'] ?> open role(s)</p>
                            <p class="admin-muted"><?= admin_h((string) $gap['suggestion']) ?></p>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>

    <div class="admin-split">
        <section class="admin-card" aria-labelledby="admin-intel-companies">
            <div class="admin-section-heading">
                <div>
                    <p class="admin-eyebrow">EMPLOYERS</p>
                    <h2 id="admin-intel-companies">How well the cohort covers each employer</h2>
                    <p class="admin-muted">Coverage is the share of a company's required skills that at least a quarter of students list.</p>
                </div>
            </div>
            <?php if ($report['company_matches'] === []): ?>
                <div class="admin-empty"><p>No approved company has published requirements yet.</p></div>
            <?php else: ?>
                <div class="admin-table-wrap">
                    <table class="admin-table admin-table--compact">
                        <thead><tr><th scope="col">Company</th><th scope="col">Roles</th><th scope="col">Coverage</th><th scope="col">Note</th></tr></thead>
                        <tbody>
                            <?php foreach ($report['company_matches'] as $row): ?>
                                <tr>
                                    <td><?= admin_h((string) $row['company']) ?></td>
                                    <td><?= (int) $row['jobs'] ?></td>
                                    <td><?= admin_h(number_format((float) $row['coverage'], 1)) ?>%</td>
                                    <td class="admin-cell-sub"><?= admin_h((string) $row['note']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="admin-card" aria-labelledby="admin-intel-trend">
            <div class="admin-section-heading">
                <div>
                    <p class="admin-eyebrow">VOLUME</p>
                    <h2 id="admin-intel-trend">Applications by month</h2>
                    <p class="admin-muted"><?= admin_h((string) $report['trend']['summary']) ?></p>
                </div>
            </div>
            <?php if (($report['trend']['points'] ?? []) === []): ?>
                <div class="admin-empty"><p>No applications recorded yet.</p></div>
            <?php else: ?>
                <div class="admin-table-wrap">
                    <table class="admin-table admin-table--compact">
                        <thead><tr><th scope="col">Month</th><th scope="col">Applications</th></tr></thead>
                        <tbody>
                            <?php foreach ($report['trend']['points'] as $point): ?>
                                <tr><td><?= admin_h((string) $point['period']) ?></td><td><?= (int) $point['count'] ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (!empty($report['trend']['note'])): ?>
                    <p class="admin-small"><?= admin_h((string) $report['trend']['note']) ?></p>
                <?php endif; ?>
                <?php if (($report['trend']['range'] ?? null) !== null): ?>
                    <p class="admin-small">Observed monthly range: <?= admin_h((string) $report['trend']['range'][0]) ?>–<?= admin_h((string) $report['trend']['range'][1]) ?> applications.</p>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </div>

    <section class="admin-card" aria-labelledby="admin-intel-flags">
        <div class="admin-section-heading">
            <div>
                <p class="admin-eyebrow">REVIEW</p>
                <h2 id="admin-intel-flags">Records worth a human look</h2>
                <p class="admin-muted">These are automated flags, not findings. Each one names a record id so you can open it and decide.</p>
            </div>
        </div>
        <?php if ($report['anomalies'] === []): ?>
            <div class="admin-empty"><p>Nothing flagged. That means the checks found nothing, not that the data is perfect.</p></div>
        <?php else: ?>
            <ul class="admin-list">
                <?php foreach ($report['anomalies'] as $flag): ?>
                    <li>
                        <strong><?= admin_h((string) $flag['type']) ?></strong>
                        <p class="admin-muted"><?= admin_h((string) $flag['detail']) ?></p>
                        <p class="admin-small"><?= admin_h((string) $flag['record']) ?> · <?= admin_h((string) $flag['action']) ?></p>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="admin-card" aria-labelledby="admin-intel-quality">
        <div class="admin-section-heading">
            <div>
                <p class="admin-eyebrow">DATA QUALITY</p>
                <h2 id="admin-intel-quality">What the report could and could not read</h2>
            </div>
        </div>
        <dl class="admin-metrics admin-metrics--four">
            <?php foreach ($report['data_quality'] as $label => $value): ?>
                <div><dt><?= admin_h(ucfirst((string) $label)) ?></dt><dd><?= (int) $value ?></dd></div>
            <?php endforeach; ?>
        </dl>
        <p class="admin-small">Missing optional tables are reported as zero rather than breaking the page. Apply <code>php scripts/migrate.php</code> to enable resume analysis, practice sessions and skill evidence.</p>
    </section>
<?php endif; ?>
