<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

/*
| Plain-English idea: this page is NOT the trustworthy source of
| truth -- a customer's browser could reach this URL with a fake
| reference typed in and nothing bad would happen, because we always
| ask Paystack directly "is this actually true?" before believing it.
| The real, reliable confirmation is the webhook (webhooks/paystack.php),
| which happens server-to-server. This page just gives the *customer*
| a friendly "success" or "failed" screen while they wait.
*/

$reference = $_GET['reference'] ?? '';

if ($reference === '') {
    http_response_code(400);
    exit('Missing payment reference.');
}

$stmt = db()->prepare('SELECT * FROM paystack_transactions WHERE reference = ? LIMIT 1');
$stmt->execute([$reference]);
$transaction = $stmt->fetch();

if (!$transaction) {
    http_response_code(404);
    exit('We could not find that payment.');
}

$keys = get_organization_paystack_keys((int) $transaction['organization_id']);

$status = 'unknown';

if ($keys) {
    try {
        $data = verify_paystack_transaction($reference, $keys['secret_key']);

        $eventType = ($data['status'] ?? '') === 'success' ? 'charge.success' : 'charge.failed';

        process_paystack_transaction_result($data, $eventType, 'callback');

        $status = $eventType === 'charge.success' ? 'success' : 'failed';

    } catch (Throwable $e) {
        error_log('Paystack callback verify failed: ' . $e->getMessage());
    }
}

$invoiceStmt = db()->prepare('SELECT public_id FROM invoices WHERE id = ? LIMIT 1');
$invoiceStmt->execute([$transaction['invoice_id']]);
$invoicePublicId = $invoiceStmt->fetchColumn();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payment <?= $status === 'success' ? 'successful' : 'status' ?></title>
    <style>
        body { font-family: system-ui, sans-serif; background: #0f0f10; color: #eee; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
        .card { text-align: center; max-width: 400px; padding: 32px; }
        .icon { font-size: 3rem; margin-bottom: 16px; }
        a { color: #2ecc71; }
    </style>
</head>
<body>
    <div class="card">
        <?php if ($status === 'success'): ?>
            <div class="icon">✅</div>
            <h1>Payment successful</h1>
            <p>Thank you — your payment has been recorded.</p>
        <?php elseif ($status === 'failed'): ?>
            <div class="icon">⚠️</div>
            <h1>Payment did not go through</h1>
            <p>No charge was made. You can try again.</p>
        <?php else: ?>
            <div class="icon">⏳</div>
            <h1>We're confirming your payment</h1>
            <p>This can take a moment. Refresh this page shortly, or check back on the invoice link.</p>
        <?php endif; ?>

        <?php if ($invoicePublicId): ?>
            <p><a href="pay.php?invoice=<?= htmlspecialchars((string) $invoicePublicId) ?>">Back to invoice</a></p>
        <?php endif; ?>
    </div>
</body>
</html>
