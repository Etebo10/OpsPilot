<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

/*
| Mailgun's tracking webhooks (delivered, permanent failure/bounce,
| complaint) send JSON, unlike the inbound-mail webhook above which
| sends form data -- that's a genuine quirk of Mailgun's API, not a
| mistake here.
|
| Same defensive pattern again: we match the ORIGINAL message we sent
| by its Message-Id (which we saved as provider_message_id when we
| sent it) to find which business this is about, THEN verify the
| signature with that business's key.
*/

$payload = json_decode(file_get_contents('php://input'), true);

if (!is_array($payload) || empty($payload['signature']) || empty($payload['event-data'])) {
    log_email_webhook_event(null, null, false, 'error', 'Unrecognized payload shape', is_array($payload) ? $payload : []);
    http_response_code(200);
    exit;
}

$eventData = $payload['event-data'];
$eventType = $eventData['event'] ?? null;
$recipient = $eventData['recipient'] ?? null;
$providerMessageId = trim((string) ($eventData['message']['headers']['message-id'] ?? ''), '<>');

$sigBlock = $payload['signature'];

$message = null;
if ($providerMessageId) {
    $stmt = db()->prepare(
        'SELECT m.id, m.conversation_id, c.organization_id
         FROM messages m INNER JOIN conversations c ON c.id = m.conversation_id
         WHERE m.provider_message_id = ? LIMIT 1'
    );
    $stmt->execute([$providerMessageId]);
    $message = $stmt->fetch();
}

if (!$message) {
    log_email_webhook_event($eventType, $recipient, false, 'ignored_unknown_recipient', 'No matching sent message found', $payload);
    http_response_code(200);
    exit;
}

$account = get_organization_email_account((int) $message['organization_id']);

if (!$account || !verify_mailgun_signature(
    (string) ($sigBlock['timestamp'] ?? ''),
    (string) ($sigBlock['token'] ?? ''),
    (string) ($sigBlock['signature'] ?? ''),
    $account['webhook_signing_key'] ?? ''
)) {
    log_email_webhook_event($eventType, $recipient, false, 'error', 'Signature verification failed', $payload);
    http_response_code(400);
    exit;
}

// Signature verified -- safe to act on this now.

if (in_array($eventType, ['failed', 'rejected'], true)) {
    db()->prepare('UPDATE messages SET status = \'failed\' WHERE id = ?')->execute([$message['id']]);

    record_delivery_attempt(
        (int) $message['id'],
        'failed',
        $eventData['delivery-status']['description'] ?? ('Mailgun event: ' . $eventType)
    );

    // Stop trying to email this contact automatically until a human
    // gets a fresh, correct address for them.
    db()->prepare(
        'UPDATE contacts SET email_bounced_at = NOW()
         WHERE id = (SELECT contact_id FROM conversations WHERE id = ?)'
    )->execute([$message['conversation_id']]);

} elseif ($eventType === 'delivered') {
    record_delivery_attempt((int) $message['id'], 'success', 'Delivered (Mailgun tracking event)');
}

log_email_webhook_event($eventType, $recipient, true, 'processed', null, $payload);
http_response_code(200);
