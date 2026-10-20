<?php
$totalStudents = 0;
$totalPlaced = 0;
$totalApplications = 0;
foreach ($branchReport as $row) {
    $totalStudents += (int) $row['students'];
    $totalPlaced += (int) $row['placed'];
    $totalApplications += (int) $row['applications'];
}
$placementRate = $totalStudents > 0 ? round(($totalPlaced / $totalStudents) * 100, 1) : 0.0;
$offeredStudents = 0;
foreach ($branchReport as $row) {
    $offeredStudents += (int) $row['offered_students'];
}
$acceptRate = $offeredStudents > 0 ? round(($totalPlaced / $offeredStudents) * 100, 1) : 0.0;
?>

<section class="admin-card" aria-labelledby="admin-report-kpi-title">
    <div class="admin-section-heading">
        <div>
            <p class="admin-eyebrow">HEADLINE</p>
            <h2 id="admin-report-kpi-title">Placement snapshot</h2>
        </div>
        <span class="admin-pill"><?= $reportYear !== '' ? 'Class of ' . admin_h($reportYear) : 'All students' ?></span>
    </div>

    <nav class="admin-chips lg-group" data-glass-group aria-label="Filter by graduation year">
        <span class="lg-indicator" aria-hidden="true">
            <span class="lg-smoke"></span>
            <span class="lg-specular"></span>
            <span class="lg-rim"></span>
            <span class="lg-caustic"></span>
            <span class="lg-glint"></span>
        </span>
        <a class="admin-chip lg-option <?= $reportYear === '' ? 'is-active' : '' ?>" data-lg-option href="admin.php?section=reports">All years</a>
        <?php foreach ($graduationYears as $year): ?>
            <a class="admin-chip lg-option <?= (string) $reportYear === (string) $year ? 'is-active' : '' ?>" data-lg-option
               href="admin.php?section=reports&amp;year=<?= (int) $year ?>"
               <?= (string) $reportYear === (string) $year ? 'aria-current="page"' : '' ?>><?= (int) $year ?></a>
        <?php endforeach; ?>
    </nav>

    <dl class="admin-metrics admin-metrics--four">
        <div><dt>Placement rate</dt><dd><?= $placementRate ?>%</dd></div>
        <div><dt>Students placed</dt><dd><?= $totalPlaced ?> / <?= $totalStudents ?></dd></div>
        <div><dt>Offer acceptance</dt><dd><?= $acceptRate ?>%</dd></div>
        <div><dt>Highest CTC</dt><dd><?= admin_h(admin_money($offersSummary['highest_ctc'] === null ? null : (string) $offersSummary['highest_ctc'])) ?></dd></div>
    </dl>
    <p class="admin-muted admin-small">
        Placement rate counts students with a <strong>joined</strong> offer, not selected applications. Students with no
        recorded offer stay in the denominator, which is what accreditation reviewers expect.
        <?= $totalApplications ?> applications are included in this view.
    </p>
</section>

<section class="admin-card" aria-labelledby="admin-branch-title">
    <div class="admin-section-heading">
        <div>
            <p class="admin-eyebrow">BRANCH</p>
            <h2 id="admin-branch-title">Branch funnel</h2>
        </div>
        <a class="admin-text-link" href="admin.php?section=reports&amp;export=branch">Export CSV</a>
    </div>

    <?php if (!$branchReport): ?>
        <p class="admin-empty">No student records to report on yet.</p>
    <?php else: ?>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th scope="col">Branch</th>
                        <th scope="col">Students</th>
                        <th scope="col">Applications</th>
                        <th scope="col">With an offer</th>
                        <th scope="col">Joined</th>
                        <th scope="col">Placement rate</th>
                        <th scope="col">Average CTC</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($branchReport as $row): ?>
                        <?php
                        $students = (int) $row['students'];
                        $rate = $students > 0 ? round(((int) $row['placed'] / $students) * 100, 1) : 0.0;
                        ?>
                        <tr>
                            <td><strong><?= admin_h((string) $row['branch']) ?></strong></td>
                            <td><?= $students ?></td>
                            <td><?= (int) $row['applications'] ?></td>
                            <td><?= (int) $row['offered_students'] ?></td>
                            <td><?= (int) $row['placed'] ?></td>
                            <td>
                                <span class="admin-bar" aria-hidden="true"><span style="width:<?= admin_h((string) min(100, $rate)) ?>%"></span></span>
                                <?= $rate ?>%
                            </td>
                            <td><?= admin_h(admin_money((string) $row['avg_ctc'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="admin-card" aria-labelledby="admin-company-title">
    <div class="admin-section-heading">
        <div>
            <p class="admin-eyebrow">COMPANIES</p>
            <h2 id="admin-company-title">Company funnel</h2>
        </div>
        <span class="admin-pill">Top <?= count($companyReport) ?></span>
    </div>

    <?php if (!$companyReport): ?>
        <p class="admin-empty">No company activity yet.</p>
    <?php else: ?>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th scope="col">Company</th>
                        <th scope="col">Applications</th>
                        <th scope="col">Selected</th>
                        <th scope="col">Accepted</th>
                        <th scope="col">Joined</th>
                        <th scope="col">Highest CTC</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($companyReport as $row): ?>
                        <tr>
                            <td><strong><?= admin_h((string) $row['company_name']) ?></strong></td>
                            <td><?= (int) $row['applications'] ?></td>
                            <td><?= (int) $row['selected'] ?></td>
                            <td><?= (int) $row['accepted'] ?></td>
                            <td><?= (int) $row['joined'] ?></td>
                            <td><?= admin_h(admin_money((string) $row['highest_ctc'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
