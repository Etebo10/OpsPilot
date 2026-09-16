<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| TELEGRAM
|--------------------------------------------------------------------------
|
| Much lighter than Paystack or Mailgun -- no business verification,
| no DNS, no domain needed. A bot token comes from a 30-second chat
| with @BotFather inside Telegram itself.
|
| Security model is simpler too: instead of computing an HMAC
| signature like Paystack/Mailgun, Telegram lets us choose a
| "secret_token" when we register our webhook, and it echoes that
| exact value back on every request in a header. We just compare it.
| Simpler mechanism, same underlying principle: never trust a webhook
| you haven't verified.
*/

const TELEGRAM_API_BASE = 'https://api.telegram.org/bot';


function get_organization_telegram_account(int $organizationId): ?array
{
    $stmt = db()->prepare(
        'SELECT id, external_account_id, config_encrypted FROM channel_accounts
         WHERE organization_id = ? AND channel_type = \'telegram\' AND is_active = 1
         LIMIT 1'
    );
    $stmt->execute([$organizationId]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    $secrets = json_decode(decrypt_secret($row['config_encrypted']), true) ?? [];

    return array_merge($secrets, [
        'channel_account_id' => $row['id'],
        'webhook_key' => $row['external_account_id'],
    ]);
}


function get_telegram_account_by_webhook_key(string $webhookKey): ?array
{
    $stmt = db()->prepare(
        'SELECT id, organization_id, config_encrypted FROM channel_accounts
         WHERE channel_type = \'telegram\' AND external_account_id = ? AND is_active = 1
         LIMIT 1'
    );
    $stmt->execute([$webhookKey]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    $secrets = json_decode(decrypt_secret($row['config_encrypted']), true) ?? [];

    return array_merge($secrets, [
        'channel_account_id' => $row['id'],
        'organization_id' => $row['organization_id'],
    ]);
}


/**
 * Saves the bot token, generates a random secret token and a random
 * (unguessable, but not sensitive) webhook key, and registers the
 * webhook with Telegram automatically -- no manual curl command
 * needed, unlike Mailgun's dashboard-based setup.
 */
function save_telegram_settings(int $organizationId, string $botToken): array
{
    $existing = db()->prepare(
        'SELECT id, external_account_id FROM channel_accounts WHERE organization_id = ? AND channel_type = \'telegram\' LIMIT 1'
    );
    $existing->execute([$organizationId]);
    $row = $existing->fetch();

    $webhookKey = $row['external_account_id'] ?? bin2hex(random_bytes(16));
    $secretToken = bin2hex(random_bytes(16));

    $secrets = encrypt_secret(json_encode([
        'bot_token' => $botToken,
        'secret_token' => $secretToken,
    ]));

    if ($row) {
        db()->prepare(
            'UPDATE channel_accounts SET config_encrypted = ?, is_active = 1 WHERE id = ?'
        )->execute([$secrets, $row['id']]);
    } else {
        db()->prepare(
            'INSERT INTO channel_accounts
                (organization_id, channel_type, display_name, external_account_id, config_encrypted, is_active, connected_at)
             VALUES (?, \'telegram\', \'Telegram\', ?, ?, 1, NOW())'
        )->execute([$organizationId, $webhookKey, $secrets]);
    }

    $webhookUrl = app_url('webhooks/telegram.php?key=' . $webhookKey);

    $registerResult = telegram_api_call($botToken, 'setWebhook', [
        'url' => $webhookUrl,
        'secret_token' => $secretToken,
    ]);

    log_action(action: 'channel.telegram_configured', entityType: 'channel_accounts', entityId: $organizationId);

    return [
        'webhook_url' => $webhookUrl,
        'registered' => !empty($registerResult['ok']),
        'telegram_response' => $registerResult['description'] ?? '',
    ];
}


function send_via_telegram(int $organizationId, string $chatId, string $text): void
{
    $account = get_organization_telegram_account($organizationId);

    if (!$account) {
        throw new RuntimeException('This business has not connected Telegram yet.');
    }

    $result = telegram_api_call($account['bot_token'], 'sendMessage', [
        'chat_id' => $chatId,
        'text' => $text,
    ]);

    if (empty($result['ok'])) {
        throw new RuntimeException('Telegram error: ' . ($result['description'] ?? 'unknown error'));
    }
}


function telegram_api_call(string $botToken, string $method, array $params): array
{
    $ch = curl_init(TELEGRAM_API_BASE . $botToken . '/' . $method);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($params),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 15,
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('Could not reach Telegram: ' . $curlError);
    }

    return json_decode($response, true) ?? [];
}
