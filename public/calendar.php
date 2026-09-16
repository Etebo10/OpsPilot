<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

require_auth();

$organizationId = (int) currentOrganizationId();

$stmt = db()->prepare(
    'SELECT
        j.id,
        j.job_number,
        j.title,
        j.status,
        j.priority,
        j.scheduled_start,
        j.scheduled_end,
        CONCAT(c.first_name, \' \', c.last_name) AS customer_name
     FROM jobs j
     INNER JOIN customers c ON c.id = j.customer_id
     WHERE j.organization_id = ?
       AND j.scheduled_start IS NOT NULL
       AND j.status NOT IN (\'cancelled\', \'completed\')
     ORDER BY j.scheduled_start ASC
     LIMIT 100'
);
$stmt->execute([$organizationId]);
$appointments = $stmt->fetchAll();

require_once __DIR__ . '/../app/views/partials/header.php';

?>
<div class="app-shell">
    <?php require __DIR__ . '/../app/views/partials/sidebar.php'; ?>
    <main class="app-main">
        <?php require __DIR__ . '/../app/views/partials/topbar.php'; ?>

        <section class="page-content">
            <div class="page-heading">
                <div>
                    <span class="eyebrow">SCHEDULE</span>
                    <h1>Calendar</h1>
                    <p>See scheduled work and the next operational commitments.</p>
                </div>
                <a class="btn btn-primary" href="jobs.php">
                    <i class="fa-solid fa-plus"></i>
                    Schedule a job
                </a>
            </div>

            <div class="glass-panel table-panel">
                <?php if (!$appointments): ?>
                    <div class="empty-state">
                        <div class="empty-icon"><i class="fa-regular fa-calendar"></i></div>
                        <h3>Your calendar is clear</h3>
                        <p>Schedule a job to see it here.</p>
                        <a class="btn btn-primary" href="jobs.php">Open jobs</a>
                    </div>
                <?php else: ?>
                    <div class="table-scroll">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>When</th>
                                    <th>Job</th>
                                    <th>Customer</th>
                                    <th>Priority</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($appointments as $appointment): ?>
                                    <tr>
                                        <td>
                                            <strong><?= e(date('D, M j', strtotime($appointment['scheduled_start']))) ?></strong>
                                            <small class="table-subtext">
                                                <?= e(date('g:i A', strtotime($appointment['scheduled_start']))) ?>
                                                <?php if ($appointment['scheduled_end']): ?>
                                                    - <?= e(date('g:i A', strtotime($appointment['scheduled_end']))) ?>
                                                <?php endif; ?>
                                            </small>
                                        </td>
                                        <td>
                                            <?= e($appointment['title']) ?>
                                            <small class="table-subtext"><?= e($appointment['job_number']) ?></small>
                                        </td>
                                        <td><?= e($appointment['customer_name']) ?></td>
                                        <td><span class="status-pill <?= e($appointment['priority']) ?>"><?= e(ucfirst($appointment['priority'])) ?></span></td>
                                        <td><span class="status-pill <?= e($appointment['status']) ?>"><?= e(ucwords(str_replace('_', ' ', $appointment['status']))) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </main>
</div>
<script src="js/app.js"></script>
<?php require_once __DIR__ . '/../app/views/partials/footer.php'; ?>
