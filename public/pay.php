<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

// No require_auth() here on purpose -- this page is for the
// business's CUSTOMER, who does not have an OpsPilot login. The
// invoice's public_id (a long random code, already in your database)
// is what makes this link safe to share without a password.

$publicId = $_GET['invoice'] ?? '';

if (!preg_match('/^[a-f0-9\-]{36}$/i', $publicId)) {
    http_response_code(404);
    exit('Invoice not found.');
}

$stmt = db()->prepare(
    'SELECT i.*, o.name AS business_name, o.id AS organization_id,
            CONCAT(c.first_name, \' \', c.last_name) AS customer_name,
            c.email AS customer_email
     FROM invoices i
     INNER JOIN organizations o ON o.id = i.organization_id
     INNER JOIN customers c ON c.id = i.customer_id
     WHERE i.public_id = ?
     LIMIT 1'
);
$stmt->execute([$publicId]);
$invoice = $stmt->fetch();

if (!$invoice) {
    http_response_code(404);
    exit('Invoice not found.');
}

$items = db()->prepare('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id ASC');
$items->execute([$invoice['id']]);
$lineItems = $items->fetchAll();

$balance = round((float) $invoice['total_amount'] - (float) $invoice['amount_paid'], 2);
$alreadyPaid = $balance <= 0 || $invoice['status'] === 'cancelled';

$keys = get_organization_paystack_keys((int) $invoice['organization_id']);
$onlinePaymentAvailable = $keys !== null;

$error = $_GET['error'] ?? null;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Invoice <?= htmlspecialchars($invoice['invoice_number']) ?> — <?= htmlspecialchars($invoice['business_name']) ?></title>
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; background: #0f0f10; color: #eee; margin: 0; padding: 24px; }
        .card { max-width: 480px; margin: 40px auto; background: #1a1a1c; border-radius: 12px; padding: 32px; border: 1px solid rgba(255,255,255,0.08); }
        h1 { font-size: 1.3rem; margin: 0 0 4px; }
        .muted { color: #9a9a9e; font-size: 0.9rem; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; font-size: 0.9rem; }
        td { padding: 8px 0; border-bottom: 1px solid rgba(255,255,255,0.06); }
        .totals { margin-top: 16px; }
        .totals div { display: flex; justify-content: space-between; padding: 4px 0; }
        .balance { font-size: 1.4rem; font-weight: 700; margin: 16px 0; }
        .btn { display: block; width: 100%; text-align: center; background: #2ecc71; color: #06210f; font-weight: 700; padding: 14px; border-radius: 8px; border: none; font-size: 1rem; cursor: pointer; }
        .btn:disabled { background: #444; color: #999; cursor: not-allowed; }
        .paid-banner { background: rgba(46,204,113,0.15); color: #2ecc71; padding: 12px; border-radius: 8px; text-align: center; font-weight: 600; }
        .error-banner { background: rgba(231,76,60,0.15); color: #e74c3c; padding: 12px; border-radius: 8px; margin-bottom: 16px; }
    </style>
</head>
<body>
    <div class="card">
        <h1><?= htmlspecialchars($invoice['business_name']) ?></h1>
        <p class="muted">Invoice <?= htmlspecialchars($invoice['invoice_number']) ?> for <?= htmlspecialchars($invoice['customer_name']) ?></p>

        <?php if ($error): ?>
            <div class="error-banner">We couldn't start that payment. Please try again.</div>
        <?php endif; ?>

        <table>
            <?php foreach ($lineItems as $item): ?>
                <tr>
                    <td><?= htmlspecialchars($item['description']) ?></td>
                    <td style="text-align:right;">₦<?= number_format((float) $item['total'], 2) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>

        <div class="totals">
            <div><span>Subtotal</span><span>₦<?= number_format((float) $invoice['subtotal'], 2) ?></span></div>
            <div><span>Tax</span><span>₦<?= number_format((float) $invoice['tax_amount'], 2) ?></span></div>
            <div><span>Paid so far</span><span>₦<?= number_format((float) $invoice['amount_paid'], 2) ?></span></div>
        </div>

        <div class="balance">Balance due: ₦<?= number_format($balance, 2) ?></div>

        <?php if ($alreadyPaid): ?>
            <div class="paid-banner">This invoice is fully paid. Thank you!</div>
        <?php elseif (!$onlinePaymentAvailable): ?>
            <p class="muted">Online payment isn't set up for this business yet. Please contact them directly to pay.</p>
        <?php else: ?>
            <form method="POST" action="pay_initialize.php">
                <input type="hidden" name="invoice" value="<?= htmlspecialchars($publicId) ?>">
                <input type="email" name="email" required placeholder="Your email address"
                       value="<?= htmlspecialchars($invoice['customer_email'] ?? '') ?>"
                       style="width:100%; box-sizing:border-box; padding:12px; margin-bottom:12px; border-radius:8px; border:1px solid #444; background:#111; color:#eee;">
                <button type="submit" class="btn">Pay ₦<?= number_format($balance, 2) ?> now</button>
            </form>
            <p class="muted" style="margin-top:12px;">You'll be taken to Paystack's secure checkout. We never see or store your card details.</p>
        <?php endif; ?>
    </div>
</body>
</html>
