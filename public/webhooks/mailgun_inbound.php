<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

/*
| Mailgun posts inbound mail as multipart form data (not JSON) --
| that's why we read $_POST directly instead of php://input here,
| unlike the Paystack webhook.
|
| Same defensive order as the Paystack webhook: look up which
| business this belongs to from the (unverified) recipient address
| first, THEN verify the signature using that business's own signing
| key, and only proceed if it checks out.
*/

$recipient = strtolower(trim($_POST['recipient'] ?? ''));
$sender = trim($_POST['from'] ?? $_POST['sender'] ?? '');
$subject = trim($_POST['subject'] ?? '(no subject)');
$bodyPlain = $_POST['body-plain'] ?? '';
$messageId = trim($_POST['Message-Id'] ?? '');

$timestamp = $_POST['timestamp'] ?? '';
$token = $_POST['token'] ?? '';
$signature = $_POST['signature'] ?? '';

if (!$recipient) {
    log_email_webhook_event(null, null, false, 'error', 'Missing recipient', $_POST);
    http_response_code(200);
    exit;
}

$stmt = db()->prepare(
    'SELECT id, organization_id, config_encrypted FROM channel_accounts
     WHERE channel_type = \'email\'
       AND LOWER(JSON_UNQUOTE(JSON_EXTRACT(public_config, \'$.from_address\'))) = ?
     LIMIT 1'
);
$stmt->execute([$recipient]);
$account = $stmt->fetch();

if (!$account) {
    log_email_webhook_event('inbound', $recipient, false, 'ignored_unknown_recipient', null, $_POST);
    http_response_code(200);
    exit;
}

$secrets = json_decode(decrypt_secret($account['config_encrypted']), true) ?? [];

if (!verify_mailgun_signature($timestamp, $token, $signature, $secrets['webhook_signing_key'] ?? '')) {
    log_email_webhook_event('inbound', $recipient, false, 'error', 'Signature verification failed', $_POST);
    http_response_code(400);
    exit;
}

// Signature is genuinely valid -- safe to act on this now.

$attachment = null;
if (!empty($_FILES) && !empty($_POST['attachment-count'])) {
    $first = $_FILES['attachment-1'] ?? null;
    if ($first && $first['error'] === UPLOAD_ERR_OK) {
        $attachment = $first;
    }
}

// A simple "Name <email>" sender header needs splitting into parts.
$senderName = null;
$senderEmail = $sender;
if (preg_match('/^(.*?)<(.+)>$/', $sender, $m)) {
    $senderName = trim($m[1], ' "');
    $senderEmail = trim($m[2]);
}

try {
    receive_inbound_message(
        organizationId: (int) $account['organization_id'],
        channelType: 'email',
        channelAccountId: (int) $account['id'],
        contactName: $senderName ?: null,
        contactEmail: $senderEmail ?: null,
        contactPhone: null,
        body: $bodyPlain,
        attachment: $attachment,
        externalThreadId: $messageId ?: null,
        subject: $subject
    );

    log_email_webhook_event('inbound', $recipient, true, 'processed', null, $_POST);

} catch (Throwable $e) {
    log_email_webhook_event('inbound', $recipient, true, 'error', $e->getMessage(), $_POST);
}

http_response_code(200);
