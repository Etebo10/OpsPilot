<?php

declare(strict_types=1);



const PAYSTACK_API_BASE = 'https://api.paystack.co';



function paystack_api_request(string $method, string $endpoint, ?array $body, string $secretKey): array
{
    $ch = curl_init(PAYSTACK_API_BASE . $endpoint);

    $headers = [
        'Authorization: Bearer ' . $secretKey,
        'Content-Type: application/json',
    ];

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $responseBody = curl_exec($ch);
    $curlError = curl_error($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($responseBody === false) {
        throw new RuntimeException('Could not reach Paystack: ' . $curlError);
    }

    $decoded = json_decode($responseBody, true);

    if (!is_array($decoded)) {
        throw new RuntimeException('Paystack returned an unreadable response.');
    }

    if ($statusCode >= 400 || empty($decoded['status'])) {
        throw new RuntimeException(
            'Paystack error: ' . ($decoded['message'] ?? 'Unknown error, HTTP ' . $statusCode)
        );
    }

    return $decoded;
}


/**
 * Fetch and decrypt a business's stored Paystack keys.
 * Returns null if they haven't set payments up yet.
 */
function get_organization_paystack_keys(int $organizationId): ?array
{
    $stmt = db()->prepare(
        'SELECT public_key, secret_key_encrypted
         FROM organization_payment_providers
         WHERE organization_id = ? AND provider = \'paystack\' AND is_active = 1
         LIMIT 1'
    );
    $stmt->execute([$organizationId]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    return [
        'public_key' => $row['public_key'],
        'secret_key' => decrypt_secret($row['secret_key_encrypted']),
    ];
}


/**
 * Save (or replace) a business's Paystack keys. The secret key is
 * encrypted before it ever touches the database.
 */
function save_organization_paystack_keys(int $organizationId, string $publicKey, string $secretKey): void
{
    $encrypted = encrypt_secret($secretKey);

    $stmt = db()->prepare(
        'INSERT INTO organization_payment_providers
            (organization_id, provider, public_key, secret_key_encrypted, is_active)
         VALUES (?, \'paystack\', ?, ?, 1)
         ON DUPLICATE KEY UPDATE
            public_key = VALUES(public_key),
            secret_key_encrypted = VALUES(secret_key_encrypted),
            is_active = 1'
    );
    $stmt->execute([$organizationId, $publicKey, $encrypted]);

    log_action(
        action: 'paystack.keys_updated',
        entityType: 'organization_payment_providers',
        entityId: $organizationId
    );
}


/**
 * Step 1: start a payment. Called from the public "pay this invoice"
 * page. Computes the amount from the invoice itself -- the browser
 * never gets a say in how much gets charged.
 */
function initialize_paystack_payment(int $organizationId, int $invoiceId, string $customerEmail): array
{
    $invoice = get_invoice($organizationId, $invoiceId);

    if (!$invoice) {
        throw new RuntimeException('Invoice not found.');
    }

    if ($invoice['status'] === 'cancelled') {
        throw new RuntimeException('This invoice has been cancelled.');
    }

    $balance = round((float) $invoice['total_amount'] - (float) $invoice['amount_paid'], 2);

    if ($balance <= 0) {
        throw new RuntimeException('This invoice is already fully paid.');
    }

    $keys = get_organization_paystack_keys($organizationId);

    if (!$keys) {
        throw new RuntimeException('This business has not set up online payments yet.');
    }

    $reference = 'PSK_' . bin2hex(random_bytes(12));
    $amountKobo = (int) round($balance * 100);

    // Create OUR record of this attempt before we ever talk to
    // Paystack, so a webhook that arrives later always has something
    // to match against.
    $stmt = db()->prepare(
        'INSERT INTO paystack_transactions
            (public_id, organization_id, invoice_id, reference, amount_kobo,
             currency, status, customer_email)
         VALUES (?, ?, ?, ?, ?, \'NGN\', \'pending\', ?)'
    );
    $stmt->execute([uuid(), $organizationId, $invoiceId, $reference, $amountKobo, $customerEmail]);

    try {
        $response = paystack_api_request('POST', '/transaction/initialize', [
            'email' => $customerEmail,
            'amount' => $amountKobo,
            'reference' => $reference,
            'currency' => 'NGN',
            'callback_url' => app_url('pay_callback.php'),
        ], $keys['secret_key']);

    } catch (Throwable $e) {
        db()->prepare(
            'UPDATE paystack_transactions SET status = \'failed\', gateway_response = ? WHERE reference = ?'
        )->execute([substr($e->getMessage(), 0, 250), $reference]);

        throw $e;
    }

    return [
        'authorization_url' => $response['data']['authorization_url'] ?? null,
        'reference' => $reference,
    ];
}


/**
 * Step 2 (server-to-server double check): ask Paystack directly what
 * the real status of a transaction is, rather than trusting whatever
 * the customer's browser claims when it redirects back to us.
 */
function verify_paystack_transaction(string $reference, string $secretKey): array
{
    $response = paystack_api_request(
        'GET',
        '/transaction/verify/' . rawurlencode($reference),
        null,
        $secretKey
    );

    return $response['data'] ?? [];
}


/**
 * The single function that turns "Paystack says this happened" into
 * "our invoice reflects that." Called by BOTH pay_callback.php and
 * webhooks/paystack.php -- written so calling it twice for the exact
 * same event is always harmless (that's the idempotency guarantee).
 */
function process_paystack_transaction_result(array $data, string $eventType, string $source): string
{
    $reference = $data['reference'] ?? null;

    if (!$reference) {
        log_webhook_event('paystack', $eventType, null, true, 'error', 'Missing reference in payload', $data);
        return 'error';
    }

    $pdo = db();

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'SELECT * FROM paystack_transactions WHERE reference = ? FOR UPDATE'
        );
        $stmt->execute([$reference]);
        $transaction = $stmt->fetch();

        if (!$transaction) {
            $pdo->commit();
            log_webhook_event('paystack', $eventType, $reference, true, 'ignored_unknown_reference', null, $data);
            return 'ignored_unknown_reference';
        }

        // Idempotency: if we've already resolved this transaction to a
        // final state, do nothing more -- this is what makes replayed
        // or duplicate webhooks safe.
        if (in_array($transaction['status'], ['success', 'failed'], true) && $eventType !== 'refund.processed') {
            $pdo->commit();
            log_webhook_event('paystack', $eventType, $reference, true, 'duplicate_ignored', null, $data);
            return 'duplicate_ignored';
        }

        $organizationId = (int) $transaction['organization_id'];
        $invoiceId = (int) $transaction['invoice_id'];

        if ($eventType === 'charge.success') {

            $paidAmountKobo = (int) ($data['amount'] ?? 0);

            if ($paidAmountKobo !== (int) $transaction['amount_kobo']) {
                $update = $pdo->prepare(
                    'UPDATE paystack_transactions SET status = \'disputed\', gateway_response = ? WHERE id = ?'
                );
                $update->execute(['Amount mismatch: expected ' . $transaction['amount_kobo'] . ', got ' . $paidAmountKobo, $transaction['id']]);
                $pdo->commit();

                log_webhook_event('paystack', $eventType, $reference, true, 'error', 'Amount mismatch', $data);
                return 'error';
            }

            $update = $pdo->prepare(
                'UPDATE paystack_transactions
                 SET status = \'success\', paystack_transaction_id = ?, channel = ?,
                     gateway_response = ?, verified_at = NOW()
                 WHERE id = ?'
            );
            $update->execute([
                $data['id'] ?? null,
                $data['channel'] ?? null,
                $data['gateway_response'] ?? null,
                $transaction['id'],
            ]);

            $pdo->commit();

            // apply_payment_to_invoice() runs its OWN transaction and
            // row lock -- the exact same function the manual "record a
            // payment" page uses. This is the payoff of Phase 2.
            apply_payment_to_invoice(
                organizationId: $organizationId,
                invoiceId: $invoiceId,
                amount: $paidAmountKobo / 100,
                method: 'online',
                reference: $reference,
                notes: 'Paid via Paystack (' . ($data['channel'] ?? 'card') . ')',
                actorUserId: null,
                source: 'paystack'
            );

            log_webhook_event('paystack', $eventType, $reference, true, 'processed', null, $data);
            return 'processed';
        }

        if ($eventType === 'charge.failed') {
            $update = $pdo->prepare(
                'UPDATE paystack_transactions SET status = \'failed\', gateway_response = ? WHERE id = ?'
            );
            $update->execute([$data['gateway_response'] ?? 'Payment failed', $transaction['id']]);
            $pdo->commit();

            log_webhook_event('paystack', $eventType, $reference, true, 'processed', null, $data);
            return 'processed';
        }

        if ($eventType === 'refund.processed') {
            $refundedAmount = ((int) ($data['amount'] ?? 0)) / 100;

            $pdo->commit(); // release the row lock before calling another function that locks it itself

            reverse_payment_on_invoice(
                organizationId: $organizationId,
                invoiceId: $invoiceId,
                paystackTransactionId: (int) $transaction['id'],
                amount: $refundedAmount,
                reason: 'Refunded via Paystack'
            );

            db()->prepare(
                'UPDATE paystack_transactions SET status = \'refunded\' WHERE id = ?'
            )->execute([$transaction['id']]);

            log_webhook_event('paystack', $eventType, $reference, true, 'processed', null, $data);
            return 'processed';
        }

        if ($eventType === 'charge.dispute.create') {
            $update = $pdo->prepare(
                'UPDATE paystack_transactions SET status = \'disputed\' WHERE id = ?'
            );
            $update->execute([$transaction['id']]);
            $pdo->commit();

            log_action(
                action: 'payment.disputed',
                entityType: 'invoice',
                entityId: $invoiceId,
                metadata: ['reference' => $reference]
            );

            log_webhook_event('paystack', $eventType, $reference, true, 'processed', null, $data);
            return 'processed';
        }

        // Any event type we don't specifically handle: acknowledge it
        // (so Paystack stops retrying) without changing anything.
        $pdo->commit();
        log_webhook_event('paystack', $eventType, $reference, true, 'ignored_event_type', null, $data);
        return 'ignored_event_type';

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        log_webhook_event('paystack', $eventType, $reference, true, 'error', $e->getMessage(), $data);
        return 'error';
    }
}


/**
 * Every webhook -- processed, ignored, or rejected -- gets one row
 * here. This is your reconciliation trail.
 */
function log_webhook_event(
    string $provider,
    ?string $eventType,
    ?string $reference,
    bool $signatureValid,
    string $result,
    ?string $errorMessage,
    array $rawPayload
): void {
    try {
        $stmt = db()->prepare(
            'INSERT INTO payment_webhook_events
                (provider, event_type, reference, signature_valid, processing_result, error_message, raw_payload)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $provider,
            $eventType,
            $reference,
            $signatureValid ? 1 : 0,
            $result,
            $errorMessage,
            json_encode($rawPayload),
        ]);
    } catch (Throwable $e) {
        error_log('log_webhook_event failed: ' . $e->getMessage());
    }
}


/**
 * Build a full URL to a public page, using APP_URL from .env --
 * needed because Paystack needs an absolute URL to redirect back to.
 */
function app_url(string $path): string
{
    $base = rtrim(getenv('APP_URL') ?: 'http://localhost/opspilot/public', '/');
    return $base . '/' . ltrim($path, '/');
}
