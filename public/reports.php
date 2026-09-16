<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

require_auth();

$organizationId = (int) currentOrganizationId();
$pdo = db();

function report_value(PDO $pdo, string $sql, int $organizationId): float
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$organizationId]);
    return (float) $stmt->fetchColumn();
}

$customerCount = report_value(
    $pdo,
    'SELECT COUNT(*) FROM customers WHERE organization_id = ? AND status = \'active\'',
    $organizationId
);

$completedJobs = report_value(
    $pdo,
    'SELECT COUNT(*) FROM jobs WHERE organization_id = ? AND status = \'completed\'',
    $organizationId
);

$invoiced = report_value(
    $pdo,
    'SELECT COALESCE(SUM(total_amount), 0) FROM invoices
     WHERE organization_id = ? AND status != \'cancelled\'',
    $organizationId
);

$collected = report_value(
    $pdo,
    'SELECT COALESCE(SUM(amount_paid), 0) FROM invoices WHERE organization_id = ?',
    $organizationId
);

$outstanding = max($invoiced - $collected, 0);
$collectionRate = $invoiced > 0 ? round(($collected / $invoiced) * 100, 1) : 0;

$topCustomers = $pdo->prepare(
    'SELECT
        CONCAT(c.first_name, \' \', c.last_name) AS customer_name,
        c.total_spent,
        COUNT(DISTINCT j.id) AS jobs,
        COUNT(DISTINCT i.id) AS invoices
     FROM customers c
     LEFT JOIN jobs j ON j.customer_id = c.id AND j.organization_id = c.organization_id
     LEFT JOIN invoices i ON i.customer_id = c.id AND i.organization_id = c.organization_id
     WHERE c.organization_id = ? AND c.status = \'active\'
     GROUP BY c.id
     ORDER BY c.total_spent DESC, c.created_at DESC
     LIMIT 10'
);
$topCustomers->execute([$organizationId]);
$customers = $topCustomers->fetchAll();

require_once __DIR__ . '/../app/views/partials/header.php';

?>
<div class="app-shell">
    <?php require __DIR__ . '/../app/views/partials/sidebar.php'; ?>
    <main class="app-main">
        <?php require __DIR__ . '/../app/views/partials/topbar.php'; ?>

        <section class="page-content">
            <div class="page-heading">
                <div>
                    <span class="eyebrow">INTELLIGENCE</span>
                    <h1>Business reports</h1>
                    <p>Decision-ready metrics from your operational records.</p>
                </div>
                <a class="btn btn-primary" href="ai.php">
                    <i class="fa-solid fa-wand-magic-sparkles"></i>
                    Ask OpsPilot
                </a>
            </div>

            <div class="metrics">
                <article class="metric glass-panel">
                    <span>Active customers</span>
                    <strong><?= e(number_format($customerCount)) ?></strong>
                    <small>Current customer base</small>
                </article>
                <article class="metric glass-panel">
                    <span>Completed jobs</span>
                    <strong><?= e(number_format($completedJobs)) ?></strong>
                    <small>Work delivered</small>
                </article>
                <article class="metric glass-panel">
                    <span>Collected</span>
                    <strong>₦<?= e(number_format($collected, 2)) ?></strong>
                    <small><?= e(number_format($collectionRate, 1)) ?>% collection rate</small>
                </article>
                <article class="metric glass-panel">
                    <span>Outstanding</span>
                    <strong>₦<?= e(number_format($outstanding, 2)) ?></strong>
                    <small>Open receivables</small>
                </article>
            </div>

            <div class="glass-panel table-panel">
                <div class="table-header">
                    <div>
                        <strong>Customer value</strong>
                        <span>Revenue and activity by active customer.</span>
                    </div>
                </div>
                <?php if ($customers): ?>
                    <div class="table-scroll">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Customer</th>
                                    <th>Jobs</th>
                                    <th>Invoices</th>
                                    <th>Collected</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($customers as $customer): ?>
                                    <tr>
                                        <td><?= e($customer['customer_name']) ?></td>
                                        <td><?= e(number_format((int) $customer['jobs'])) ?></td>
                                        <td><?= e(number_format((int) $customer['invoices'])) ?></td>
                                        <td><strong>₦<?= e(number_format((float) $customer['total_spent'], 2)) ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state"><p>Add customers and invoices to generate reports.</p></div>
                <?php endif; ?>
            </div>
        </section>
    </main>
</div>
<script src="js/app.js"></script>
<?php require_once __DIR__ . '/../app/views/partials/footer.php'; ?>
