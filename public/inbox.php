<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

require_auth();
require_permission('inbox.manage');

$user = current_user();
$organizationId = (int) $user['organization_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'start_test_conversation') {
        $name = trim($_POST['test_name'] ?? '');
        $email = trim($_POST['test_email'] ?? '');
        $body = trim($_POST['test_message'] ?? '');

        if ($name === '' || $body === '') {
            flash('error', 'Enter a name and a message.');
            redirect_to('inbox.php');
        }

        // Simulates what will happen automatically once the real
        // website chat widget is built in Phase 6 -- useful for
        // testing everything else (assignment, replies, notes,
        // search, status) right now.
        $result = receive_inbound_message(
            organizationId: $organizationId,
            channelType: 'website_chat',
            channelAccountId: null,
            contactName: $name,
            contactEmail: $email ?: null,
            contactPhone: null,
            body: $body
        );

        flash('success', 'Test conversation started.');
        redirect_to('conversation.php?id=' . $result['conversation_id']);
    }
}

$statusFilter = $_GET['status'] ?? 'open';
$assignedFilter = $_GET['assigned'] ?? 'anyone';
$search = trim($_GET['q'] ?? '');

$where = ['c.organization_id = ?'];
$params = [$organizationId];

if (in_array($statusFilter, ['open', 'pending', 'resolved', 'archived'], true)) {
    $where[] = 'c.status = ?';
    $params[] = $statusFilter;
}

if ($assignedFilter === 'me') {
    $where[] = 'c.assigned_to_user_id = ?';
    $params[] = $user['id'];
} elseif ($assignedFilter === 'unassigned') {
    $where[] = 'c.assigned_to_user_id IS NULL';
}

if ($search !== '') {
    $where[] = '(ct.name LIKE ? OR ct.email LIKE ? OR ct.phone LIKE ? OR EXISTS (
        SELECT 1 FROM messages m WHERE m.conversation_id = c.id AND m.body LIKE ?
    ))';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
}

$sql = 'SELECT c.*, ct.name AS contact_name, ct.email AS contact_email,
               u.name AS assigned_name,
               (SELECT body FROM messages WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) AS last_message_body
        FROM conversations c
        INNER JOIN contacts ct ON ct.id = c.contact_id
        LEFT JOIN users u ON u.id = c.assigned_to_user_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY c.last_message_at DESC
        LIMIT 100';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$conversations = $stmt->fetchAll();

$channelIcons = [
    'website_chat' => 'fa-solid fa-comment',
    'email' => 'fa-solid fa-envelope',
    'whatsapp' => 'fa-brands fa-whatsapp',
    'instagram' => 'fa-brands fa-instagram',
    'telegram' => 'fa-brands fa-telegram',
];

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
                    <span class="eyebrow">INBOX</span>
                    <h1>Conversations</h1>
                    <p>Every conversation, from every connected channel, in one place.</p>
                </div>
                <button class="btn btn-primary" data-modal-open="testConvoModal">
                    <i class="fa-solid fa-flask"></i> Start test conversation
                </button>
            </div>

            <form method="GET" class="glass-panel table-panel" style="padding: 16px 20px; display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" onchange="this.form.submit()">
                        <?php foreach (['open', 'pending', 'resolved', 'archived', 'all'] as $s): ?>
                            <option value="<?= e($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Assigned</label>
                    <select name="assigned" onchange="this.form.submit()">
                        <option value="anyone" <?= $assignedFilter === 'anyone' ? 'selected' : '' ?>>Anyone</option>
                        <option value="me" <?= $assignedFilter === 'me' ? 'selected' : '' ?>>Assigned to me</option>
                        <option value="unassigned" <?= $assignedFilter === 'unassigned' ? 'selected' : '' ?>>Unassigned</option>
                    </select>
                </div>
                <div class="form-group" style="flex:1; min-width:200px;">
                    <label>Search</label>
                    <input type="text" name="q" value="<?= e($search) ?>" placeholder="Name, email, phone, or message text">
                </div>
                <button type="submit" class="btn btn-ghost">Search</button>
            </form>

            <div class="glass-panel table-panel">
                <?php if (!$conversations): ?>
                    <div class="empty-state">
                        <div class="empty-icon"><i class="fa-solid fa-inbox"></i></div>
                        <h3>Nothing here</h3>
                        <p>Try a different filter, or start a test conversation to try things out.</p>
                    </div>
                <?php else: ?>
                    <div class="table-scroll">
                        <table class="data-table">
                            <thead><tr><th></th><th>Contact</th><th>Last message</th><th>Assigned</th><th>Status</th><th></th></tr></thead>
                            <tbody>
                                <?php foreach ($conversations as $conversation): ?>
                                    <tr>
                                        <td><i class="<?= e($channelIcons[$conversation['channel_type']] ?? 'fa-solid fa-message') ?>"></i></td>
                                        <td>
                                            <?= e($conversation['contact_name'] ?: $conversation['contact_email'] ?: 'Unknown') ?>
                                            <?php if ($conversation['unread_count'] > 0): ?>
                                                <span class="badge badge-overdue"><?= (int) $conversation['unread_count'] ?> new</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="max-width:320px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                            <?= e($conversation['last_message_body'] ?? '') ?>
                                        </td>
                                        <td><?= $conversation['assigned_name'] ? e($conversation['assigned_name']) : '<span class="muted">Unassigned</span>' ?></td>
                                        <td><span class="badge <?= $conversation['status'] === 'resolved' ? 'badge-paid' : ($conversation['status'] === 'open' ? 'badge-partially_paid' : '') ?>"><?= e(ucfirst($conversation['status'])) ?></span></td>
                                        <td><a class="btn btn-ghost btn-sm" href="conversation.php?id=<?= (int) $conversation['id'] ?>">Open</a></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </main>
</div>

<div class="modal-backdrop" id="testConvoModal" aria-hidden="true">
    <div class="modal">
        <div class="modal-header">
            <div><span class="eyebrow">TESTING</span><h2>Start a test conversation</h2></div>
            <button class="modal-close" data-modal-close type="button"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="start_test_conversation">
            <p class="muted" style="padding: 0 0 12px;">
                This simulates a message arriving the way it will automatically once the real website chat widget is connected (Phase 6). Good for testing replies, notes, assignment, and search today.
            </p>
            <div class="form-grid">
                <div class="form-group full">
                    <label>Contact name</label>
                    <input type="text" name="test_name" required>
                </div>
                <div class="form-group full">
                    <label>Contact email (optional)</label>
                    <input type="email" name="test_email">
                </div>
                <div class="form-group full">
                    <label>Their message</label>
                    <textarea name="test_message" rows="3" required></textarea>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Start conversation</button>
            </div>
        </form>
    </div>
</div>
<script src="js/app.js"></script>
<?php require_once __DIR__ . '/../app/views/partials/footer.php'; ?>
