<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| EMAIL (Mailgun)
|--------------------------------------------------------------------------
|
| We're using Mailgun because it does BOTH jobs email needs here --
| sending (with proper SPF/DKIM so your emails don't land in spam) and
| receiving (routing incoming mail on your domain to us as a webhook)
| -- on one platform, with one API key per business.
|
| Same "encrypted per-business credentials" pattern as Paystack:
| api_key and webhook_signing_key are encrypted with crypto.php;
| domain and from-address aren't secret, so they live in plain
| public_config alongside them.
*/

function get_organization_email_account(int $organizationId): ?array
{
    $stmt = db()->prepare(
        'SELECT * FROM channel_accounts
         WHERE organization_id = ? AND channel_type = \'email\' AND is_active = 1
         LIMIT 1'
    );
    $stmt->execute([$organizationId]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    $secrets = json_decode(decrypt_secret($row['config_encrypted']), true) ?? [];
    $public = json_decode((string) $row['public_config'], true) ?? [];

    return array_merge($public, $secrets, ['channel_account_id' => $row['id']]);
}


function save_email_settings(
    int $organizationId,
    string $domain,
    string $apiKey,
    string $webhookSigningKey,
    string $fromAddress,
    string $fromName
): void {
    $secrets = encrypt_secret(json_encode([
        'api_key' => $apiKey,
        'webhook_signing_key' => $webhookSigningKey,
    ]));

    $public = json_encode([
        'domain' => $domain,
        'from_address' => $fromAddress,
        'from_name' => $fromName,
    ]);

    $existing = db()->prepare(
        'SELECT id FROM channel_accounts WHERE organization_id = ? AND channel_type = \'email\' LIMIT 1'
    );
    $existing->execute([$organizationId]);
    $row = $existing->fetch();

    if ($row) {
        db()->prepare(
            'UPDATE channel_accounts SET config_encrypted = ?, public_config = ?, is_active = 1 WHERE id = ?'
        )->execute([$secrets, $public, $row['id']]);
    } else {
        db()->prepare(
            'INSERT INTO channel_accounts
                (organization_id, channel_type, display_name, config_encrypted, public_config, is_active, connected_at)
             VALUES (?, \'email\', \'Email\', ?, ?, 1, NOW())'
        )->execute([$organizationId, $secrets, $public]);
    }

    log_action(action: 'channel.email_configured', entityType: 'channel_accounts', entityId: $organizationId);
}


/**
 * Actually sends an email through Mailgun's API. Returns the
 * provider's own message id, which we store so a later bounce
 * webhook can be matched back to this exact message.
 */
function send_via_mailgun(
    int $organizationId,
    string $to,
    string $subject,
    string $bodyText,
    ?string $inReplyToMessageId = null
): string {
    $account = get_organization_email_account($organizationId);

    if (!$account) {
        throw new RuntimeException('This business has not connected email yet.');
    }

    $fields = [
        'from' => $account['from_name'] . ' <' . $account['from_address'] . '>',
        'to' => $to,
        'subject' => $subject,
        'text' => $bodyText,
    ];

    if ($inReplyToMessageId) {
        $fields['h:In-Reply-To'] = $inReplyToMessageId;
        $fields['h:References'] = $inReplyToMessageId;
    }

    $ch = curl_init('https://api.mailgun.net/v3/' . $account['domain'] . '/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_USERPWD => 'api:' . $account['api_key'],
        CURLOPT_TIMEOUT => 20,
    ]);

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('Could not reach Mailgun: ' . $curlError);
    }

    $decoded = json_decode($response, true);

    if ($status >= 400 || !is_array($decoded) || empty($decoded['id'])) {
        throw new RuntimeException('Mailgun error: ' . ($decoded['message'] ?? ('HTTP ' . $status)));
    }

    return trim((string) $decoded['id'], '<>');
}


/**
 * Mailgun signs every webhook (inbound mail AND delivery/bounce
 * events) the same way: HMAC-SHA256 of timestamp+token, using a
 * signing key from your Mailgun dashboard. We also reject anything
 * more than 15 minutes old, so a captured old request can't be
 * replayed later.
 */
function verify_mailgun_signature(string $timestamp, string $token, string $signature, string $signingKey): bool
{
    if (abs(time() - (int) $timestamp) > 900) {
        return false;
    }

    $expected = hash_hmac('sha256', $timestamp . $token, $signingKey);

    return hash_equals($expected, $signature);
}


function log_email_webhook_event(
    ?string $eventType,
    ?string $recipient,
    bool $signatureValid,
    string $result,
    ?string $errorMessage,
    array $rawPayload
): void {
    try {
        db()->prepare(
            'INSERT INTO email_webhook_events
                (event_type, recipient, signature_valid, processing_result, error_message, raw_payload)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $eventType,
            $recipient,
            $signatureValid ? 1 : 0,
            $result,
            $errorMessage,
            json_encode($rawPayload),
        ]);
    } catch (Throwable $e) {
        error_log('log_email_webhook_event failed: ' . $e->getMessage());
    }
}
