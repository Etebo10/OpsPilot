<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

/*
|--------------------------------------------------------------------------
| PAYSTACK WEBHOOK
|--------------------------------------------------------------------------
|
| This is the one URL you paste into your Paystack dashboard (under
| Settings -> API Keys & Webhooks -> Webhook URL). Paystack calls THIS
| URL directly from their servers, in the background, whenever
| something happens to a payment -- this is more reliable than the
| customer's browser redirect, since it still fires even if the
| customer closes the tab right after paying.
|
| SECURITY -- read this before touching anything below:
| Because different businesses on this platform each have their OWN
| Paystack account and their OWN secret key, we have a chicken-and-egg
| problem: we need to know WHICH business's secret key to check the
| signature against, but we don't know which business it is until we
| read the payload -- and we can't trust the payload until the
| signature is checked!
|
| The safe way out: we look up the transaction reference (which WE
| generated ourselves when the customer clicked "pay", and which is
| essentially unguessable -- 24 random hex characters) to find which
| business it belongs to. ONLY THEN do we check the signature, using
| that business's real secret key. If the reference doesn't match one
| of our own pending transactions, or the signature doesn't match, we
| refuse to process anything -- so there is no way for someone to fake
| a payment onto a business's invoice.
*/

$rawBody = file_get_contents('php://input');
$signatureHeader = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';

$payload = json_decode($rawBody, true);

if (!is_array($payload) || empty($payload['event']) || empty($payload['data']['reference'])) {
    // Not a shape we recognize at all -- acknowledge so Paystack
    // doesn't keep retrying something we will never understand, but
    // log it for you to look at.
    log_webhook_event('paystack', $payload['event'] ?? null, null, false, 'error', 'Unrecognized payload shape', is_array($payload) ? $payload : []);
    http_response_code(200);
    exit;
}

$eventType = $payload['event'];
$reference = $payload['data']['reference'];

// Step 1: find which business this reference belongs to (unverified
// so far -- we don't trust anything about it yet).
$stmt = db()->prepare(
    'SELECT organization_id FROM paystack_transactions WHERE reference = ? LIMIT 1'
);
$stmt->execute([$reference]);
$owner = $stmt->fetch();

if (!$owner) {
    // Likely Paystack's own test ping when you first set up the
    // webhook, or an event about a reference we don't recognize.
    // We never act on it either way.
    log_webhook_event('paystack', $eventType, $reference, false, 'ignored_unknown_reference', null, $payload);
    http_response_code(200);
    exit;
}

$keys = get_organization_paystack_keys((int) $owner['organization_id']);

if (!$keys) {
    log_webhook_event('paystack', $eventType, $reference, false, 'error', 'Organization has no active Paystack keys', $payload);
    http_response_code(200);
    exit;
}

// Step 2: NOW check the signature, using that business's real secret
// key, against the exact raw bytes Paystack sent us.
$expectedSignature = hash_hmac('sha512', $rawBody, $keys['secret_key']);

if (!hash_equals($expectedSignature, (string) $signatureHeader)) {
    log_webhook_event('paystack', $eventType, $reference, false, 'error', 'Signature verification failed', $payload);
    http_response_code(400);
    exit;
}

// Step 3: signature is genuinely valid -- safe to process now.
$result = process_paystack_transaction_result($payload['data'], $eventType, 'webhook');

// Always answer 200 once we've handled it (even if we chose to
// ignore the event type) so Paystack marks the delivery successful
// and stops retrying.
http_response_code(200);
echo json_encode(['received' => true, 'result' => $result]);
