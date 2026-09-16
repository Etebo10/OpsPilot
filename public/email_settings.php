<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

require_auth();
require_permission('channels.manage');

$user = current_user();
$organizationId = (int) $user['organization_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $domain = trim($_POST['domain'] ?? '');
    $apiKey = trim($_POST['api_key'] ?? '');
    $webhookSigningKey = trim($_POST['webhook_signing_key'] ?? '');
    $fromAddress = trim($_POST['from_address'] ?? '');
    $fromName = trim($_POST['from_name'] ?? '') ?: $user['organization_id'];

    if ($domain === '' || $apiKey === '' || $webhookSigningKey === '' || !filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Fill in your Mailgun domain, API key, webhook signing key, and a valid from-address.');
        redirect_to('email_settings.php');
    }

    try {
        save_email_settings($organizationId, $domain, $apiKey, $webhookSigningKey, $fromAddress, $fromName);
        flash('success', 'Email settings saved.');
    } catch (Throwable $e) {
        flash('error', 'Could not save: ' . $e->getMessage());
    }

    redirect_to('email_settings.php');
}

$stmt = db()->prepare(
    'SELECT public_config, updated_at FROM channel_accounts WHERE organization_id = ? AND channel_type = \'email\' LIMIT 1'
);
$stmt->execute([$organizationId]);
$existing = $stmt->fetch();
$config = $existing ? (json_decode((string) $existing['public_config'], true) ?? []) : [];

$inboundWebhookUrl = app_url('webhooks/mailgun_inbound.php');
$eventsWebhookUrl = app_url('webhooks/mailgun_events.php');

require_once __DIR__ . '/../app/views/partials/header.php';

?>
<link rel="stylesheet" href="css/phase4-invoices.css">
<div class="app-shell">
    <?php require __DIR__ . '/../app/views/partials/sidebar.php'; ?>
    <main class="app-main">
        <?php require __DIR__ . '/../app/views/partials/topbar.php'; ?>

        <section class="page-content">
            <div class="page-heading">
                <div>
                    <span class="eyebrow">CHANNELS</span>
                    <h1>Email</h1>
                    <p>Send and receive customer emails through the inbox.</p>
                </div>
            </div>

            <div class="glass-panel table-panel" style="max-width: 560px;">
                <?php if ($existing): ?>
                    <div class="empty-state" style="text-align:left; padding: 16px 0;">
                        <p><i class="fa-solid fa-circle-check" style="color:#2ecc71;"></i> Connected — sending as <code><?= e($config['from_address'] ?? '') ?></code></p>
                    </div>
                <?php endif; ?>

                <form method="POST" style="padding: 20px;">
                    <?= csrf_field() ?>
                    <div class="form-grid">
                        <div class="form-group full">
                            <label>Mailgun domain</label>
                            <input type="text" name="domain" value="<?= e($config['domain'] ?? '') ?>" placeholder="mg.yourbusiness.com" required>
                        </div>
                        <div class="form-group full">
                            <label>Mailgun API key</label>
                            <input type="password" name="api_key" placeholder="key-..." required autocomplete="new-password">
                        </div>
                        <div class="form-group full">
                            <label>Mailgun webhook signing key</label>
                            <input type="password" name="webhook_signing_key" placeholder="Found in Mailgun → Settings → Webhooks" required autocomplete="new-password">
                        </div>
                        <div class="form-group full">
                            <label>Send emails from (address)</label>
                            <input type="email" name="from_address" value="<?= e($config['from_address'] ?? '') ?>" placeholder="support@yourbusiness.com" required>
                        </div>
                        <div class="form-group full">
                            <label>Send emails from (name)</label>
                            <input type="text" name="from_name" value="<?= e($config['from_name'] ?? '') ?>" placeholder="Your Business Support">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary" style="margin-top:12px;">
                        <i class="fa-solid fa-envelope"></i> <?= $existing ? 'Update' : 'Connect'; ?> email
                    </button>
                </form>
            </div>

            <div class="glass-panel table-panel" style="max-width: 700px; margin-top:20px;">
                <div class="table-header">
                    <div><strong>Webhook URLs</strong><span>Paste these into your Mailgun dashboard.</span></div>
                </div>
                <div style="padding: 0 20px 20px;">
                    <p><strong>Receiving Settings → Routes</strong> (forward mail sent to your address here):</p>
                    <code style="display:block; padding:10px 14px; background: rgba(0,0,0,0.3); border-radius:8px; word-break:break-all; margin-bottom:16px;"><?= e($inboundWebhookUrl) ?></code>

                    <p><strong>Sending → Webhooks</strong> (subscribe this to "delivered" and "permanent failure" events):</p>
                    <code style="display:block; padding:10px 14px; background: rgba(0,0,0,0.3); border-radius:8px; word-break:break-all;"><?= e($eventsWebhookUrl) ?></code>
                </div>
                <p class="muted" style="padding: 0 20px 20px;">
                    <strong>Important:</strong> real inbound routing needs a domain you actually own, with its MX
                    records pointed at Mailgun — this can't be tested on <code>localhost</code> alone.
                    Outbound sending, however, works today even on Mailgun's free sandbox domain, to any email
                    address you've added as an "authorized recipient" in your Mailgun dashboard.
                </p>
            </div>
        </section>
    </main>
</div>
<script src="js/app.js"></script>
<?php require_once __DIR__ . '/../app/views/partials/footer.php'; ?>
