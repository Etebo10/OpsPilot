<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

require_auth();

$user = current_user();
$organizationId = (int) $user['organization_id'];

$invoiceId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$invoiceId) {
    flash('error', 'Invalid invoice.');
    redirect_to('invoices.php');
}

// get_invoice() is scoped to organization_id, so someone can never
// view another business's invoice by changing the number in the URL.
$invoice = get_invoice($organizationId, $invoiceId);

if (!$invoice) {
    flash('error', 'Invoice not found.');
    redirect_to('invoices.php');
}

$balance = round((float) $invoice['total_amount'] - (float) $invoice['amount_paid'], 2);

require_once __DIR__ . '/../app/views/partials/header.php';

?>
<link rel="stylesheet" href="css/phase4-invoices.css">
<div class="app-shell">
    <?php require __DIR__ . '/../app/views/partials/sidebar.php'; ?>
    <main class="app-main">
        <?php require __DIR__ . '/../app/views/partials/topbar.php'; ?>

        <section class="page-content">
            <div class="page-heading">
                <div>
                    <span class="eyebrow">FINANCE</span>
                    <h1><?= e($invoice['invoice_number']) ?></h1>
                    <p><?= e($invoice['customer_name']) ?></p>
                </div>
                <a class="btn btn-ghost" href="invoices.php">
                    <i class="fa-solid fa-arrow-left"></i> Back to invoices
                </a>
            </div>

            <div class="glass-panel table-panel">
                <div class="table-header">
                    <div>
                        <strong>Line items</strong>
                        <span>What this invoice is for.</span>
                    </div>
                </div>
                <div class="table-scroll">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Description</th>
                                <th>Qty</th>
                                <th>Unit price</th>
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($invoice['items'] as $item): ?>
                                <tr>
                                    <td><?= e($item['description']) ?></td>
                                    <td><?= e(rtrim(rtrim(number_format((float) $item['quantity'], 2), '0'), '.')) ?></td>
                                    <td>₦<?= e(number_format((float) $item['unit_price'], 2)) ?></td>
                                    <td>₦<?= e(number_format((float) $item['total'], 2)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="invoice-totals">
                    <div><span>Subtotal</span><strong>₦<?= e(number_format((float) $invoice['subtotal'], 2)) ?></strong></div>
                    <div><span>Tax</span><strong>₦<?= e(number_format((float) $invoice['tax_amount'], 2)) ?></strong></div>
                    <div class="grand-total"><span>Total</span><strong>₦<?= e(number_format((float) $invoice['total_amount'], 2)) ?></strong></div>
                    <div><span>Paid</span><strong>₦<?= e(number_format((float) $invoice['amount_paid'], 2)) ?></strong></div>
                    <div><span>Balance</span><strong>₦<?= e(number_format($balance, 2)) ?></strong></div>
                </div>

                <?php if ($balance > 0 && $invoice['status'] !== 'cancelled' && can($user, 'payments.record')): ?>
                    <a class="btn btn-primary" href="payments.php" style="margin-top: 16px;">
                        <i class="fa-solid fa-money-bill"></i> Record a payment
                    </a>
                <?php endif; ?>

                <?php if ($balance > 0 && $invoice['status'] !== 'cancelled'): ?>
                    <?php $payLink = app_url('pay.php?invoice=' . $invoice['public_id']); ?>
                    <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid rgba(255,255,255,0.08);">
                        <strong>Customer payment link</strong>
                        <p class="muted" style="margin: 4px 0 8px;">Send this to your customer so they can pay online.</p>
                        <code style="display:block; padding: 10px 14px; background: rgba(0,0,0,0.3); border-radius: 8px; word-break: break-all;">
                            <?= e($payLink) ?>
                        </code>
                    </div>
                <?php endif; ?>
            </div>

            <div class="glass-panel table-panel">
                <div class="table-header">
                    <div>
                        <strong>Payment history</strong>
                        <span>Every payment recorded against this invoice.</span>
                    </div>
                </div>
                <?php if (!$invoice['payments']): ?>
                    <div class="empty-state"><p>No payments recorded yet.</p></div>
                <?php else: ?>
                    <div class="table-scroll">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Reference</th>
                                    <th>Method</th>
                                    <th>Amount</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($invoice['payments'] as $payment): ?>
                                    <tr>
                                        <td><?= e($payment['reference'] ?: $payment['public_id']) ?></td>
                                        <td><?= e(ucwords(str_replace('_', ' ', $payment['payment_method']))) ?></td>
                                        <td><strong>₦<?= e(number_format((float) $payment['amount'], 2)) ?></strong></td>
                                        <td><?= e(date('M j, Y g:ia', strtotime($payment['paid_at']))) ?></td>
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
