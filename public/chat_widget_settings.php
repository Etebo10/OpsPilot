<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

require_auth();
require_permission('channels.manage');

$user = current_user();
$organizationId = (int) $user['organization_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $allowedOrigins = trim($_POST['allowed_origins'] ?? '');
    $welcomeMessage = trim($_POST['welcome_message'] ?? '') ?: 'Hi! How can we help?';
    $color = trim($_POST['color'] ?? '') ?: '#2ecc71';

    if ($allowedOrigins === '') {
        flash('error', 'Enter at least one website domain (e.g. www.yourbusiness.com).');
        redirect_to('chat_widget_settings.php');
    }

    save_website_chat_settings($organizationId, $allowedOrigins, $welcomeMessage, $color);

    flash('success', 'Website chat settings saved.');
    redirect_to('chat_widget_settings.php');
}

$existing = db()->prepare(
    'SELECT * FROM channel_accounts WHERE organization_id = ? AND channel_type = \'website_chat\' LIMIT 1'
);
$existing->execute([$organizationId]);
$existing = $existing->fetch();

$config = $existing ? (json_decode((string) $existing['public_config'], true) ?? []) : [];
$widgetApiUrl = app_url('widget_api.php');
$widgetJsUrl = app_url('js/widget.js');

$embedSnippet = $existing
    ? "<script src=\"{$widgetJsUrl}\" data-widget-key=\"{$existing['external_account_id']}\" data-api=\"{$widgetApiUrl}\" async></script>"
    : null;

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
                    <h1>Website chat</h1>
                    <p>A chat bubble your customers can use on your own website.</p>
                </div>
            </div>

            <div class="glass-panel table-panel" style="max-width: 560px;">
                <form method="POST" style="padding: 20px;">
                    <?= csrf_field() ?>
                    <div class="form-grid">
                        <div class="form-group full">
                            <label>Website domain(s) allowed to show this widget</label>
                            <input type="text" name="allowed_origins"
                                   value="<?= e($config['allowed_origins'] ?? '') ?>"
                                   placeholder="www.yourbusiness.com, yourbusiness.com" required>
                            <p class="muted" style="margin-top:4px;">Comma-separated. This is what stops anyone else from embedding your chat on a different site.</p>
                        </div>
                        <div class="form-group full">
                            <label>Welcome message</label>
                            <input type="text" name="welcome_message" value="<?= e($config['welcome_message'] ?? 'Hi! How can we help?') ?>">
                        </div>
                        <div class="form-group">
                            <label>Bubble color</label>
                            <input type="color" name="color" value="<?= e($config['color'] ?? '#2ecc71') ?>">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary" style="margin-top: 12px;">
                        <i class="fa-solid fa-comment-dots"></i>
                        <?= $existing ? 'Update' : 'Turn on'; ?> website chat
                    </button>
                </form>
            </div>

            <?php if ($embedSnippet): ?>
            <div class="glass-panel table-panel" style="max-width: 700px; margin-top: 20px;">
                <div class="table-header">
                    <div>
                        <strong>Your embed code</strong>
                        <span>Paste this right before the closing <code>&lt;/body&gt;</code> tag on your website.</span>
                    </div>
                </div>
                <code style="display:block; margin: 0 20px 20px; padding: 12px; background: rgba(0,0,0,0.3); border-radius: 8px; word-break: break-all;">
                    <?= e($embedSnippet) ?>
                </code>
                <p class="muted" style="padding: 0 20px 20px;">
                    It will only actually appear on pages served from a domain you listed above — nowhere else, even if someone copies this code.
                </p>
            </div>
            <?php endif; ?>
        </section>
    </main>
</div>
<script src="js/app.js"></script>
<?php require_once __DIR__ . '/../app/views/partials/footer.php'; ?>
