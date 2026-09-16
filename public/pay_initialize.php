<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to('index.php');
}

$publicId = $_POST['invoice'] ?? '';
$email = trim($_POST['email'] ?? '');

if (!preg_match('/^[a-f0-9\-]{36}$/i', $publicId) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: pay.php?invoice=' . urlencode($publicId) . '&error=1');
    exit;
}

$stmt = db()->prepare(
    'SELECT id, organization_id FROM invoices WHERE public_id = ? LIMIT 1'
);
$stmt->execute([$publicId]);
$invoice = $stmt->fetch();

if (!$invoice) {
    http_response_code(404);
    exit('Invoice not found.');
}

try {
    // The amount charged is calculated inside this function from the
    // invoice itself -- nothing from $_POST feeds into that number.
    $result = initialize_paystack_payment(
        (int) $invoice['organization_id'],
        (int) $invoice['id'],
        $email
    );

    if (empty($result['authorization_url'])) {
        throw new RuntimeException('Paystack did not return a checkout link.');
    }

    header('Location: ' . $result['authorization_url']);
    exit;

} catch (Throwable $e) {
    error_log('Paystack initialize failed: ' . $e->getMessage());
    header('Location: pay.php?invoice=' . urlencode($publicId) . '&error=1');
    exit;
}
