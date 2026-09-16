<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

require_auth();
require_permission('channels.manage');

$user = current_user();
$organizationId = (int) $user['organization_id'];

$registrationResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $botToken = trim($_POST['bot_token'] ?? '');

    if (!preg_match('/^\d+:[A-Za-z0-9_-]+$/', $botToken)) {
        flash('error', 'That doesn\'t look like a Telegram bot token (should look like 123456789:AAExample...).');
        redirect_to('telegram_settings.php');
    }

    try {
        $registrationResult = save_telegram_settings($organizationId, $botToken);

        if ($registrationResult['registered']) {
            flash('success', 'Telegram connected and webhook registered automatically!');
        } else {
            flash('error', 'Saved, but Telegram rejected the webhook: ' . $registrationResult['telegram_response']);
        }
    } catch (Throwable $e) {
        flash('error', 'Could not connect: ' . $e->getMessage());
    }

    redirect_to('telegram_settings.php');
}

$existing = db()->prepare(
    'SELECT id, updated_at FROM channel_accounts WHERE organization_id = ? AND channel_type = \'telegram\' LIMIT 1'
);
$existing->execute([$organizationId]);
$existing = $existing->fetch();

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
                    <h1>Telegram</h1>
                    <p>Let customers message your business on Telegram.</p>
                </div>
            </div>

            <div class="glass-panel table-panel" style="max-width: 560px;">
                <div style="padding: 20px 20px 0;">
                    <strong>Before you start — getting a bot token (2 minutes, no verification):</strong>
                    <ol style="margin: 8px 0 0; padding-left: 20px; color: #b8b2a6;">
                        <li>Open Telegram, search for <strong>@BotFather</strong>, start a chat.</li>
                        <li>Send <code>/newbot</code>, give it a name and a username ending in "bot".</li>
                        <li>BotFather replies with your token — paste it below.</li>
                    </ol>
                </div>

                <?php if ($existing): ?>
                    <div class="empty-state" style="text-align:left; padding: 16px 20px 0;">
                        <p><i class="fa-solid fa-circle-check" style="color:#2ecc71;"></i> Connected — last updated <?= e(date('M j, Y', strtotime($existing['updated_at']))) ?></p>
                    </div>
                <?php endif; ?>

                <form method="POST" style="padding: 20px;">
                    <?= csrf_field() ?>
                    <div class="form-group full">
                        <label>Bot token</label>
                        <input type="password" name="bot_token" placeholder="123456789:AAExample..." required autocomplete="new-password">
                    </div>
                    <button type="submit" class="btn btn-primary" style="margin-top:12px;">
                        <i class="fa-brands fa-telegram"></i> <?= $existing ? 'Update' : 'Connect'; ?> Telegram
                    </button>
                </form>
            </div>

            <div class="glass-panel table-panel" style="max-width: 560px; margin-top: 20px;">
                <div class="table-header">
                    <div><strong>One thing before this works on your laptop</strong></div>
                </div>
                <p style="padding: 0 20px 20px; color: #b8b2a6;">
                    Telegram's servers need to reach your webhook the same way Paystack's did back in
                    Phase 3 — <code>localhost</code> isn't reachable from the outside internet. Start
                    <strong>ngrok</strong> the same way you did before (<code>ngrok http 80</code>), then
                    connect your bot using that ngrok address as your <code>APP_URL</code> in <code>.env</code>
                    (temporarily, while testing) so the webhook Telegram registers actually points somewhere
                    it can reach. Once connected, message your bot from Telegram and it should land in your
                    <a href="inbox.php">Inbox</a> within a second or two.
                </p>
            </div>
        </section>
    </main>
</div>
<script src="js/app.js"></script>
<?php require_once __DIR__ . '/../app/views/partials/footer.php'; ?>
