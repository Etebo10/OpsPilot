<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

require_auth();
require_permission('automation.view_failed_runs');

$user = current_user();
$organizationId = (int) $user['organization_id'];

$availableEvents = [
    'invoice.created' => 'Invoice created',
    'invoice.overdue' => 'Invoice overdue',
    'invoice.paid' => 'Invoice fully paid',
    'payment.received' => 'Payment received',
    'payment.refunded' => 'Payment refunded',
    'customer.created' => 'New customer added',
    'job.completed' => 'Job completed',
    'inbox.new_message' => 'New inbox message',
];

$availableActions = [
    'notify_staff' => 'Notify staff (in-app)',
    'send_email' => 'Send email',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_rule') {
        require_permission('automation.manage');

        $name = trim($_POST['name'] ?? '');
        $event = $_POST['event'] ?? '';
        $ruleAction = $_POST['rule_action'] ?? '';
        $configMessage = trim($_POST['config_message'] ?? '');
        $configEmail = trim($_POST['config_email'] ?? '');

        if ($name === '' || !isset($availableEvents[$event]) || !isset($availableActions[$ruleAction])) {
            flash('error', 'Fill in all fields with valid options.');
            redirect_to('automation.php');
        }

        $config = [];
        if ($configMessage !== '') {
            $config['message'] = $configMessage;
        }
        if ($ruleAction === 'send_email' && $configEmail !== '') {
            $config['to'] = $configEmail;
        }

        db()->prepare(
            'INSERT INTO automation_rules (organization_id, name, event, action, action_config, is_active)
             VALUES (?, ?, ?, ?, ?, 1)'
        )->execute([$organizationId, $name, $event, $ruleAction, json_encode($config)]);

        flash('success', 'Automation rule created.');
        redirect_to('automation.php');
    }

    if ($action === 'toggle_rule') {
        require_permission('automation.manage');

        $ruleId = filter_input(INPUT_POST, 'rule_id', FILTER_VALIDATE_INT);

        db()->prepare(
            'UPDATE automation_rules SET is_active = NOT is_active WHERE id = ? AND organization_id = ?'
        )->execute([$ruleId, $organizationId]);

        flash('success', 'Rule updated.');
        redirect_to('automation.php');
    }

    if ($action === 'retry_job') {
        $jobId = filter_input(INPUT_POST, 'job_id', FILTER_VALIDATE_INT);

        retry_job($organizationId, $jobId);

        flash('success', 'Job re-queued — it will run on the next worker cycle.');
        redirect_to('automation.php');
    }
}

$rules = db()->prepare(
    'SELECT * FROM automation_rules WHERE organization_id = ? ORDER BY created_at DESC'
);
$rules->execute([$organizationId]);
$rules = $rules->fetchAll();

$queueSummary = db()->prepare(
    'SELECT status, COUNT(*) AS total FROM jobs_queue WHERE organization_id = ? GROUP BY status'
);
$queueSummary->execute([$organizationId]);
$queueCounts = array_column($queueSummary->fetchAll(), 'total', 'status');

$failedJobs = db()->prepare(
    "SELECT * FROM jobs_queue
     WHERE organization_id = ? AND status IN ('dead_letter', 'failed')
     ORDER BY updated_at DESC LIMIT 50"
);
$failedJobs->execute([$organizationId]);
$failedJobs = $failedJobs->fetchAll();

$notifications = db()->prepare(
    'SELECT * FROM staff_notifications WHERE organization_id = ? ORDER BY created_at DESC LIMIT 20'
);
$notifications->execute([$organizationId]);
$notifications = $notifications->fetchAll();

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
                    <span class="eyebrow">AUTOMATION</span>
                    <h1>Automation</h1>
                    <p>Rules that run automatically, and a look at anything that didn't go as planned.</p>
                </div>
            </div>

            <div class="glass-panel table-panel">
                <div class="table-header">
                    <div>
                        <strong>Queue health</strong>
                        <span>Right now, across all your automation.</span>
                    </div>
                </div>
                <div style="display:flex; gap:24px; padding: 0 20px 20px; flex-wrap: wrap;">
                    <?php foreach (['pending', 'processing', 'completed', 'failed', 'dead_letter'] as $status): ?>
                        <div>
                            <div style="font-size:1.6rem; font-weight:700;"><?= (int) ($queueCounts[$status] ?? 0) ?></div>
                            <div class="muted"><?= e(ucfirst(str_replace('_', ' ', $status))) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if (can($user, 'automation.manage')): ?>
            <div class="glass-panel table-panel">
                <div class="table-header">
                    <div>
                        <strong>Rules</strong>
                        <span>When X happens, do Y.</span>
                    </div>
                </div>

                <?php if ($rules): ?>
                <div class="table-scroll">
                    <table class="data-table">
                        <thead><tr><th>Name</th><th>Event</th><th>Action</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                            <?php foreach ($rules as $rule): ?>
                                <tr>
                                    <td><?= e($rule['name']) ?></td>
                                    <td><?= e($availableEvents[$rule['event']] ?? $rule['event']) ?></td>
                                    <td><?= e($availableActions[$rule['action']] ?? $rule['action']) ?></td>
                                    <td><?= $rule['is_active'] ? '<span class="badge badge-paid">Active</span>' : '<span class="badge">Paused</span>' ?></td>
                                    <td>
                                        <form method="POST" class="inline-form">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="toggle_rule">
                                            <input type="hidden" name="rule_id" value="<?= (int) $rule['id'] ?>">
                                            <button type="submit" class="btn btn-ghost btn-sm">
                                                <?= $rule['is_active'] ? 'Pause' : 'Activate' ?>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                    <div class="empty-state"><p>No rules yet — add one below.</p></div>
                <?php endif; ?>

                <form method="POST" style="padding: 20px; border-top: 1px solid rgba(255,255,255,0.08);">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create_rule">
                    <div class="form-grid">
                        <div class="form-group full">
                            <label>Rule name</label>
                            <input type="text" name="name" placeholder="Notify me when an invoice is overdue" required>
                        </div>
                        <div class="form-group">
                            <label>When this happens</label>
                            <select name="event" required>
                                <?php foreach ($availableEvents as $value => $label): ?>
                                    <option value="<?= e($value) ?>"><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Do this</label>
                            <select name="rule_action" required onchange="document.getElementById('emailField').style.display = this.value === 'send_email' ? 'block' : 'none'">
                                <?php foreach ($availableActions as $value => $label): ?>
                                    <option value="<?= e($value) ?>"><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group full" id="emailField" style="display:none;">
                            <label>Send email to</label>
                            <input type="email" name="config_email" placeholder="you@yourbusiness.com">
                        </div>
                        <div class="form-group full">
                            <label>Custom message (optional)</label>
                            <input type="text" name="config_message" placeholder="Leave blank to use a default message">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary" style="margin-top: 12px;">
                        <i class="fa-solid fa-plus"></i> Add rule
                    </button>
                </form>
            </div>
            <?php endif; ?>

            <div class="glass-panel table-panel">
                <div class="table-header">
                    <div>
                        <strong>Needs attention</strong>
                        <span>Jobs that failed or gave up retrying — safe to retry once you've fixed the cause.</span>
                    </div>
                </div>
                <?php if (!$failedJobs): ?>
                    <div class="empty-state">
                        <div class="empty-icon"><i class="fa-solid fa-circle-check"></i></div>
                        <h3>Nothing needs attention</h3>
                    </div>
                <?php else: ?>
                    <div class="table-scroll">
                        <table class="data-table">
                            <thead><tr><th>Job</th><th>Status</th><th>Attempts</th><th>Last error</th><th></th></tr></thead>
                            <tbody>
                                <?php foreach ($failedJobs as $job): ?>
                                    <tr>
                                        <td><?= e($job['job_type']) ?></td>
                                        <td><span class="badge badge-overdue"><?= e($job['status']) ?></span></td>
                                        <td><?= (int) $job['attempts'] ?> / <?= (int) $job['max_attempts'] ?></td>
                                        <td style="max-width:300px;"><?= e($job['last_error'] ?? '') ?></td>
                                        <td>
                                            <form method="POST" class="inline-form">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="retry_job">
                                                <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                                                <button type="submit" class="btn btn-primary btn-sm">Retry</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="glass-panel table-panel">
                <div class="table-header">
                    <div><strong>Recent notifications</strong></div>
                </div>
                <?php if (!$notifications): ?>
                    <div class="empty-state"><p>Nothing yet.</p></div>
                <?php else: ?>
                    <div class="table-scroll">
                        <table class="data-table">
                            <tbody>
                                <?php foreach ($notifications as $notification): ?>
                                    <tr>
                                        <td><?= e($notification['message']) ?></td>
                                        <td class="muted"><?= e(date('M j, g:ia', strtotime($notification['created_at']))) ?></td>
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
<script src="js/app.js"></script>
<?php require_once __DIR__ . '/../app/views/partials/footer.php'; ?>
