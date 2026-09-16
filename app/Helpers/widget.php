<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| WEBSITE CHAT WIDGET
|--------------------------------------------------------------------------
|
| Plain-English map:
|
|   - Each business gets a random "widget key" (NOT a secret -- it's
|     embedded in their website's public HTML source, same as every
|     chat-widget product does this). The widget key tells us WHICH
|     business a visitor is chatting with.
|   - We separately check the browser's Origin/Referer against the
|     domain(s) the business told us they'd embed the widget on. This
|     is what stops someone copy-pasting the embed snippet onto an
|     unrelated website and pretending to be that business's chat.
|   - Visitors aren't logged in, so instead of a session, we hand
|     their browser a signed token (like a wristband) that says "you
|     are contact #123" -- signed so it can't be forged into claiming
|     to be a different contact, but with no password involved.
*/


function generate_widget_key(): string
{
    return bin2hex(random_bytes(16));
}


function get_channel_account_by_widget_key(string $widgetKey): ?array
{
    $stmt = db()->prepare(
        'SELECT * FROM channel_accounts
         WHERE channel_type = \'website_chat\' AND external_account_id = ? AND is_active = 1
         LIMIT 1'
    );
    $stmt->execute([$widgetKey]);
    $account = $stmt->fetch();

    return $account ?: null;
}


/**
 * Compares the browser's Origin (or Referer, as a fallback -- some
 * requests don't send Origin) against the domain list the business
 * configured. Comparing by host only, so http/https and paths don't
 * matter, but the actual domain must match exactly.
 */
function is_allowed_widget_origin(array $channelAccount, ?string $originHeader, ?string $refererHeader): bool
{
    $config = json_decode((string) $channelAccount['public_config'], true) ?? [];
    $allowedDomains = array_filter(array_map('trim', explode(',', $config['allowed_origins'] ?? '')));

    if (empty($allowedDomains)) {
        return false; // no domains configured yet = nothing is allowed
    }

    $candidate = $originHeader ?: $refererHeader;

    if (!$candidate) {
        return false;
    }

    $host = parse_url($candidate, PHP_URL_HOST);

    if (!$host) {
        return false;
    }

    foreach ($allowedDomains as $domain) {
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = rtrim($domain, '/');

        if (strcasecmp($host, $domain) === 0) {
            return true;
        }
    }

    return false;
}


function sign_visitor_token(int $contactId): string
{
    $payload = base64_encode(json_encode(['contact_id' => $contactId, 'exp' => time() + (86400 * 180)]));
    $signature = hash_hmac('sha256', $payload, app_encryption_key());

    return $payload . '.' . $signature;
}


/** Returns the contact_id if the token is genuine and not expired, otherwise null. */
function verify_visitor_token(string $token): ?int
{
    $parts = explode('.', $token, 2);

    if (count($parts) !== 2) {
        return null;
    }

    [$payload, $signature] = $parts;

    $expectedSignature = hash_hmac('sha256', $payload, app_encryption_key());

    if (!hash_equals($expectedSignature, $signature)) {
        return null;
    }

    $data = json_decode((string) base64_decode($payload, true), true);

    if (!is_array($data) || empty($data['contact_id']) || ($data['exp'] ?? 0) < time()) {
        return null;
    }

    return (int) $data['contact_id'];
}


/**
 * Basic abuse protection: at most $maxPerMinute requests from the
 * same IP address per minute. Records this attempt either way.
 */
function check_widget_rate_limit(string $ipAddress, int $maxPerMinute = 12): bool
{
    $count = db()->prepare(
        'SELECT COUNT(*) FROM widget_rate_limit_hits WHERE ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)'
    );
    $count->execute([$ipAddress]);
    $recentHits = (int) $count->fetchColumn();

    db()->prepare('INSERT INTO widget_rate_limit_hits (ip_address) VALUES (?)')->execute([$ipAddress]);

    return $recentHits < $maxPerMinute;
}


function save_website_chat_settings(int $organizationId, string $allowedOrigins, string $welcomeMessage, string $color): string
{
    $existing = db()->prepare(
        'SELECT id, external_account_id FROM channel_accounts WHERE organization_id = ? AND channel_type = \'website_chat\' LIMIT 1'
    );
    $existing->execute([$organizationId]);
    $row = $existing->fetch();

    $widgetKey = $row['external_account_id'] ?? generate_widget_key();

    $config = json_encode([
        'allowed_origins' => $allowedOrigins,
        'welcome_message' => $welcomeMessage,
        'color' => $color,
    ]);

    if ($row) {
        db()->prepare(
            'UPDATE channel_accounts SET public_config = ?, is_active = 1 WHERE id = ?'
        )->execute([$config, $row['id']]);
    } else {
        db()->prepare(
            'INSERT INTO channel_accounts
                (organization_id, channel_type, display_name, external_account_id, public_config, is_active, connected_at)
             VALUES (?, \'website_chat\', \'Website chat\', ?, ?, 1, NOW())'
        )->execute([$organizationId, $widgetKey, $config]);
    }

    log_action(action: 'channel.website_chat_configured', entityType: 'channel_accounts', entityId: $organizationId);

    return $widgetKey;
}
