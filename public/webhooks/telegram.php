<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

/*
| The webhook URL itself (?key=...) tells us which business this is
| for -- that key isn't secret (it's just an unguessable identifier),
| so we still verify the X-Telegram-Bot-Api-Secret-Token header
| against the real secret we generated when connecting, before
| trusting anything in the payload.
*/

$webhookKey = $_GET['key'] ?? '';
$account = $webhookKey ? get_telegram_account_by_webhook_key($webhookKey) : null;

if (!$account) {
    http_response_code(200); // don't give an attacker a way to tell valid keys from invalid ones
    exit;
}

$providedSecret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';

if (!hash_equals($account['secret_token'] ?? '', $providedSecret)) {
    http_response_code(403);
    exit;
}

$update = json_decode(file_get_contents('php://input'), true);
$message = $update['message'] ?? null;

if (!is_array($message) || empty($message['text']) || empty($message['chat']['id'])) {
    // Telegram sends other update types too (edited messages, button
    // clicks, etc.) that we don't handle yet -- acknowledge and ignore.
    http_response_code(200);
    exit;
}

$chatId = (string) $message['chat']['id'];
$name = trim(($message['from']['first_name'] ?? '') . ' ' . ($message['from']['last_name'] ?? '')) ?: ($message['from']['username'] ?? null);

try {
    receive_inbound_message_by_channel_identity(
        organizationId: (int) $account['organization_id'],
        channelType: 'telegram',
        channelAccountId: (int) $account['channel_account_id'],
        externalId: $chatId,
        name: $name,
        body: (string) $message['text']
    );
} catch (Throwable $e) {
    error_log('Telegram webhook processing failed: ' . $e->getMessage());
}

http_response_code(200);
