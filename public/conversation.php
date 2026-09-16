<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

require_auth();
require_permission('inbox.manage');

$user = current_user();
$organizationId = (int) $user['organization_id'];

$conversationId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$conversationId) {
    flash('error', 'Invalid conversation.');
    redirect_to('inbox.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'reply') {
        $body = trim($_POST['body'] ?? '');
        $isNote = !empty($_POST['is_internal_note']);

        if ($body === '') {
            flash('error', 'Enter a message.');
            redirect_to('conversation.php?id=' . $conversationId);
        }

        $attachment = (!empty($_FILES['attachment']['name'])) ? $_FILES['attachment'] : null;

        try {
            send_message($organizationId, $conversationId, (int) $user['id'], $body, $attachment, $isNote);
            flash('success', $isNote ? 'Note added.' : 'Reply queued for sending.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }

        redirect_to('conversation.php?id=' . $conversationId);
    }

    if ($action === 'assign') {
        require_permission('inbox.assign');

        $assignTo = filter_input(INPUT_POST, 'assigned_to_user_id', FILTER_VALIDATE_INT) ?: null;
        assign_conversation($organizationId, $conversationId, $assignTo);
        flash('success', 'Assignment updated.');
        redirect_to('conversation.php?id=' . $conversationId);
    }

    if ($action === 'status') {
        try {
            update_conversation_status($organizationId, $conversationId, $_POST['status'] ?? '');
            flash('success', 'Status updated.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect_to('conversation.php?id=' . $conversationId);
    }
}

mark_conversation_read($organizationId, $conversationId);

$conversation = get_conversation($organizationId, $conversationId);

if (!$conversation) {
    flash('error', 'Conversation not found.');
    redirect_to('inbox.php');
}

$staffQuery = db()->prepare(
    "SELECT id, name FROM users WHERE organization_id = ? AND is_active = 1 ORDER BY name"
);
$staffQuery->execute([$organizationId]);
$staffMembers = $staffQuery->fetchAll();

require_once __DIR__ . '/../app/views/partials/header.php';

?>
<link rel="stylesheet" href="css/phase4-invoices.css">
<style>
    .thread { display:flex; flex-direction:column; gap:12px; padding:20px; max-height:60vh; overflow-y:auto; }
    .msg { max-width:70%; padding:10px 14px; border-radius:10px; }
    .msg-inbound { background: rgba(255,255,255,0.06); align-self:flex-start; }
    .msg-outbound { background: rgba(46,204,113,0.12); align-self:flex-end; }
    .msg-internal { background: rgba(241,196,15,0.12); align-self:center; border:1px dashed rgba(241,196,15,0.4); max-width:90%; }
    .msg-meta { font-size:0.75rem; color:#9a9a9e; margin-top:4px; }
    .inbox-layout { display:flex; gap:20px; align-items:flex-start; }
    .inbox-sidebar { width:260px; flex-shrink:0; }
</style>
<div class="app-shell">
    <?php require __DIR__ . '/../app/views/partials/sidebar.php'; ?>
    <main class="app-main">
        <?php require __DIR__ . '/../app/views/partials/topbar.php'; ?>

        <section class="page-content">
            <div class="page-heading">
                <div>
                    <span class="eyebrow">INBOX</span>
                    <h1><?= e($conversation['contact_name'] ?: $conversation['contact_email'] ?: 'Conversation') ?></h1>
                </div>
                <a class="btn btn-ghost" href="inbox.php"><i class="fa-solid fa-arrow-left"></i> Back to inbox</a>
            </div>

            <div class="inbox-layout">
                <div class="glass-panel table-panel" style="flex:1;">
                    <div class="thread">
                        <?php foreach ($conversation['messages'] as $message): ?>
                            <div class="msg msg-<?= e($message['direction']) ?>">
                                <div>
                                    <?php if ($message['direction'] === 'internal'): ?>
                                        <strong>Note:</strong>
                                    <?php endif; ?>
                                    <?= nl2br(e($message['body'])) ?>
                                </div>
                                <?php if ($message['attachment_path']): ?>
                                    <div style="margin-top:6px;">
                                        <a href="attachment_download.php?message_id=<?= (int) $message['id'] ?>">
                                            <i class="fa-solid fa-paperclip"></i> <?= e($message['attachment_original_name']) ?>
                                        </a>
                                    </div>
                                <?php endif; ?>
                                <div class="msg-meta">
                                    <?= $message['direction'] === 'inbound' ? e($conversation['contact_name'] ?: 'Contact') : e($message['sender_name'] ?? 'Staff') ?>
                                    · <?= e(date('M j, g:ia', strtotime($message['created_at']))) ?>
                                    <?php if ($message['direction'] === 'outbound'): ?>
                                        · <span class="badge"><?= e(ucfirst($message['status'])) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <form method="POST" enctype="multipart/form-data" style="padding: 16px 20px; border-top: 1px solid rgba(255,255,255,0.08);">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="reply">
                        <textarea name="body" rows="3" placeholder="Write a reply..." required style="width:100%; box-sizing:border-box; margin-bottom:8px;"></textarea>
                        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                            <div>
                                <input type="file" name="attachment" style="max-width:180px;">
                                <label style="margin-left:12px;">
                                    <input type="checkbox" name="is_internal_note" value="1"> Internal note (not sent to contact)
                                </label>
                            </div>
                            <button type="submit" class="btn btn-primary">Send</button>
                        </div>
                    </form>
                </div>

                <div class="inbox-sidebar">
                    <div class="glass-panel table-panel" style="padding: 16px;">
                        <strong>Contact</strong>
                        <p><?= e($conversation['contact_name'] ?: '—') ?></p>
                        <?php if ($conversation['contact_email']): ?><p class="muted"><?= e($conversation['contact_email']) ?></p><?php endif; ?>
                        <?php if ($conversation['contact_phone']): ?><p class="muted"><?= e($conversation['contact_phone']) ?></p><?php endif; ?>
                        <?php if ($conversation['customer_id']): ?>
                            <a href="customers.php" class="btn btn-ghost btn-sm">View as customer</a>
                        <?php endif; ?>
                    </div>

                    <div class="glass-panel table-panel" style="padding: 16px; margin-top: 16px;">
                        <strong>Status</strong>
                        <form method="POST" style="margin-top: 8px;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="status">
                            <select name="status" onchange="this.form.submit()">
                                <?php foreach (['open', 'pending', 'resolved', 'archived'] as $s): ?>
                                    <option value="<?= e($s) ?>" <?= $conversation['status'] === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </div>

                    <?php if (can($user, 'inbox.assign')): ?>
                    <div class="glass-panel table-panel" style="padding: 16px; margin-top: 16px;">
                        <strong>Assigned to</strong>
                        <form method="POST" style="margin-top: 8px;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="assign">
                            <select name="assigned_to_user_id" onchange="this.form.submit()">
                                <option value="">Unassigned</option>
                                <?php foreach ($staffMembers as $staff): ?>
                                    <option value="<?= (int) $staff['id'] ?>" <?= (int) $conversation['assigned_to_user_id'] === (int) $staff['id'] ? 'selected' : '' ?>>
                                        <?= e($staff['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </main>
</div>
<script src="js/app.js"></script>
<?php require_once __DIR__ . '/../app/views/partials/footer.php'; ?>
