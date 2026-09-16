<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

require_auth();

$user = current_user();
$organizationId = (int) $user['organization_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    require_permission('payments.record');

    $invoiceId = filter_input(INPUT_POST, 'invoice_id', FILTER_VALIDATE_INT);
    $amount = (float) ($_POST['amount'] ?? 0);
    $method = $_POST['payment_method'] ?? 'other';
    $reference = trim($_POST['reference'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    $allowedMethods = ['cash', 'bank_transfer', 'card', 'online', 'other'];

    if (!$invoiceId || $amount <= 0 || !in_array($method, $allowedMethods, true)) {
        flash('error', 'Enter a valid invoice, amount, and payment method.');
        redirect_to('payments.php');
    }

    try {
        // All the transaction handling, row-locking, and total
        // recalculation lives in one place now -- see
        // app/Helpers/invoices.php -> apply_payment_to_invoice().
        // The Paystack webhook (next phase) will call this exact
        // same function for online payments.
        apply_payment_to_invoice(
            organizationId: $organizationId,
            invoiceId: $invoiceId,
            amount: $amount,
            method: $method,
            reference: $reference ?: null,
            notes: $notes ?: null,
            actorUserId: (int) $user['id'],
            source: 'manual'
        );

        flash('success', 'Payment recorded successfully.');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect_to('payments.php');
}

$invoiceQuery = db()->prepare(
    'SELECT
        i.id,
        i.invoice_number,
        i.total_amount,
        i.amount_paid,
        i.status,
        CONCAT(c.first_name, \' \', c.last_name) AS customer_name
     FROM invoices i
     INNER JOIN customers c ON c.id = i.customer_id
     WHERE i.organization_id = ?
       AND i.status NOT IN (\'paid\', \'cancelled\')
     ORDER BY i.created_at DESC'
);
$invoiceQuery->execute([$organizationId]);
$openInvoices = $invoiceQuery->fetchAll();

$paymentQuery = db()->prepare(
    'SELECT
        p.*,
        i.invoice_number,
        CONCAT(c.first_name, \' \', c.last_name) AS customer_name
     FROM payments p
     INNER JOIN invoices i ON i.id = p.invoice_id
     INNER JOIN customers c ON c.id = p.customer_id
     WHERE p.organization_id = ?
     ORDER BY p.paid_at DESC
     LIMIT 100'
);
$paymentQuery->execute([$organizationId]);
$payments = $paymentQuery->fetchAll();

require_once __DIR__ . '/../app/views/partials/header.php';

?>
<div class="app-shell">
    <?php require __DIR__ . '/../app/views/partials/sidebar.php'; ?>
    <main class="app-main">
        <?php require __DIR__ . '/../app/views/partials/topbar.php'; ?>

        <section class="page-content">
            <div class="page-heading">
                <div>
                    <span class="eyebrow">CASHFLOW</span>
                    <h1>Payments</h1>
                    <p>Record collections and keep invoice balances accurate.</p>
                </div>
            </div>

            <div class="glass-panel table-panel">
                <div class="table-header">
                    <div>
                        <strong>Outstanding invoices</strong>
                        <span>Record a payment against an open invoice.</span>
                    </div>
                </div>

                <?php if (!$openInvoices): ?>
                    <div class="empty-state">
                        <div class="empty-icon"><i class="fa-solid fa-circle-check"></i></div>
                        <h3>Nothing to collect</h3>
                        <p>Open invoices will appear here when they need payment.</p>
                    </div>
                <?php else: ?>
                    <div class="table-scroll">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Invoice</th>
                                    <th>Customer</th>
                                    <th>Outstanding</th>
                                    <th>Record payment</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($openInvoices as $invoice): ?>
                                    <?php $balance = (float) $invoice['total_amount'] - (float) $invoice['amount_paid']; ?>
                                    <tr>
                                        <td><a href="invoice.php?id=<?= (int) $invoice['id'] ?>"><?= e($invoice['invoice_number']) ?></a></td>
                                        <td><?= e($invoice['customer_name']) ?></td>
                                        <td><strong>₦<?= e(number_format($balance, 2)) ?></strong></td>
                                        <td>
                                            <form method="POST" class="inline-form">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="invoice_id" value="<?= (int) $invoice['id'] ?>">
                                                <input type="number" name="amount" min="0.01" max="<?= e(number_format($balance, 2, '.', '')) ?>" step="0.01" placeholder="Amount" required>
                                                <select name="payment_method" required>
                                                    <option value="bank_transfer">Bank transfer</option>
                                                    <option value="cash">Cash</option>
                                                    <option value="card">Card</option>
                                                    <option value="online">Online</option>
                                                    <option value="other">Other</option>
                                                </select>
                                                <input type="text" name="reference" placeholder="Reference">
                                                <button type="submit" class="btn btn-primary">Record</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="glass-panel table-panel">
                <div class="table-header">
                    <div>
                        <strong>Payment history</strong>
                        <span>Recent tenant-scoped collections.</span>
                    </div>
                </div>
                <?php if ($payments): ?>
                    <div class="table-scroll">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Reference</th>
                                    <th>Customer</th>
                                    <th>Invoice</th>
                                    <th>Method</th>
                                    <th>Amount</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payments as $payment): ?>
                                    <tr>
                                        <td><?= e($payment['reference'] ?: $payment['public_id']) ?></td>
                                        <td><?= e($payment['customer_name']) ?></td>
                                        <td><?= e($payment['invoice_number']) ?></td>
                                        <td><?= e(ucwords(str_replace('_', ' ', $payment['payment_method']))) ?></td>
                                        <td><strong>₦<?= e(number_format((float) $payment['amount'], 2)) ?></strong></td>
                                        <td><?= e(date('M j, Y', strtotime($payment['paid_at']))) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state"><p>No payments recorded yet.</p></div>
                <?php endif; ?>
            </div>
        </section>
    </main>
</div>
<script src="js/app.js"></script>
<?php require_once __DIR__ . '/../app/views/partials/footer.php'; ?>
