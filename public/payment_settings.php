<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

require_auth();
require_permission('settings.manage');

$user = current_user();
$organizationId = (int) $user['organization_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $publicKey = trim($_POST['public_key'] ?? '');
    $secretKey = trim($_POST['secret_key'] ?? '');

    if ($publicKey === '' || $secretKey === '') {
        flash('error', 'Enter both your Paystack public key and secret key.');
        redirect_to('payment_settings.php');
    }

    if (!str_starts_with($publicKey, 'pk_')) {
        flash('error', 'That doesn\'t look like a Paystack public key (should start with pk_).');
        redirect_to('payment_settings.php');
    }

    if (!str_starts_with($secretKey, 'sk_')) {
        flash('error', 'That doesn\'t look like a Paystack secret key (should start with sk_).');
        redirect_to('payment_settings.php');
    }

    try {
        save_organization_paystack_keys($organizationId, $publicKey, $secretKey);
        flash('success', 'Paystack keys saved. Your customers can now pay invoices online.');
    } catch (Throwable $e) {
        flash('error', 'Could not save keys: ' . $e->getMessage());
    }

    redirect_to('payment_settings.php');
}

$stmt = db()->prepare(
    'SELECT public_key, is_active, updated_at FROM organization_payment_providers
     WHERE organization_id = ? AND provider = \'paystack\' LIMIT 1'
);
$stmt->execute([$organizationId]);
$existing = $stmt->fetch();

$webhookUrl = app_url('webhooks/paystack.php');

require_once __DIR__ . '/../app/views/partials/header.php';

?>
<div class="app-shell">
    <?php require __DIR__ . '/../app/views/partials/sidebar.php'; ?>
    <main class="app-main">
        <?php require __DIR__ . '/../app/views/partials/topbar.php'; ?>

        <section class="page-content">
            <div class="page-heading">
                <div>
                    <span class="eyebrow">SETTINGS</span>
                    <h1>Online payments</h1>
                    <p>Connect Paystack so customers can pay invoices online.</p>
                </div>
            </div>

            <div class="glass-panel table-panel" style="max-width: 560px;">
                <?php if ($existing): ?>
                    <div class="empty-state" style="text-align: left; padding: 16px 0;">
                        <p>
                            <i class="fa-solid fa-circle-check" style="color:#2ecc71;"></i>
                            Connected — public key ending in
                            <code><?= e(substr($existing['public_key'], -6)) ?></code>
                        </p>
                        <p class="muted">Last updated <?= e(date('M j, Y g:ia', strtotime($existing['updated_at']))) ?></p>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <?= csrf_field() ?>
                    <div class="form-grid">
                        <div class="form-group full">
                            <label>Paystack public key</label>
                            <input type="text" name="public_key" placeholder="pk_test_..." required>
                        </div>
                        <div class="form-group full">
                            <label>Paystack secret key</label>
                            <input type="password" name="secret_key" placeholder="sk_test_..." required autocomplete="new-password">
                        </div>
                    </div>
                    <div class="modal-actions" style="justify-content: flex-start;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fa-solid fa-key"></i>
                            <?= $existing ? 'Update keys' : 'Connect Paystack' ?>
                        </button>
                    </div>
                </form>
            </div>

            <div class="glass-panel table-panel" style="max-width: 560px; margin-top: 20px;">
                <div class="table-header">
                    <div>
                        <strong>Your webhook URL</strong>
                        <span>Paste this into your Paystack dashboard.</span>
                    </div>
                </div>
                <p style="padding: 0 20px 20px;">
                    In your Paystack dashboard, go to
                    <strong>Settings → API Keys &amp; Webhooks</strong> and set the
                    <strong>Webhook URL</strong> to:
                </p>
                <code style="display:block; margin: 0 20px 20px; padding: 12px; background: rgba(0,0,0,0.3); border-radius: 8px; word-break: break-all;">
                    <?= e($webhookUrl) ?>
                </code>
                <p class="muted" style="padding: 0 20px 20px;">
                    <strong>Note:</strong> while you're testing on XAMPP (<code>localhost</code>), Paystack
                    cannot reach this URL directly — you'll need a tool like
                    <strong>ngrok</strong> to give your local site a temporary public
                    address for testing. We'll walk through that together when you're
                    ready to test end-to-end.
                </p>
            </div>
        </section>
    </main>
</div>
<script src="js/app.js"></script>
<?php require_once __DIR__ . '/../app/views/partials/footer.php'; ?>
