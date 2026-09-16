<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

require_auth();

$user = current_user();
$organizationId = (int) $user['organization_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'create') {

        $customerId = filter_input(
            INPUT_POST,
            'customer_id',
            FILTER_VALIDATE_INT
        );

        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $priority = $_POST['priority'] ?? 'normal';
        $estimatedAmount = (float) ($_POST['estimated_amount'] ?? 0);
        $scheduledStart = trim($_POST['scheduled_start'] ?? '');
        $scheduledEnd = trim($_POST['scheduled_end'] ?? '');
        $jobStatus = $scheduledStart !== '' || $scheduledEnd !== ''
            ? 'scheduled'
            : 'draft';

        $allowedPriorities = [
            'low',
            'normal',
            'high',
            'urgent'
        ];

        if (!$customerId || $title === '') {
            flash('error', 'Customer and job title are required.');
            redirect_to('jobs.php');
        }

        if (!in_array($priority, $allowedPriorities, true)) {
            $priority = 'normal';
        }

        // Verify customer belongs to current organization.
        $check = db()->prepare("
            SELECT id
            FROM customers
            WHERE id = ?
              AND organization_id = ?
              AND status != 'archived'
            LIMIT 1
        ");

        $check->execute([
            $customerId,
            $organizationId
        ]);

        if (!$check->fetch()) {
            flash('error', 'Invalid customer.');
            redirect_to('jobs.php');
        }

        $jobNumber = generate_reference('JOB');

        $stmt = db()->prepare("
            INSERT INTO jobs
            (
                public_id,
                organization_id,
                customer_id,
                job_number,
                title,
                description,
                priority,
                estimated_amount,
                scheduled_start,
                scheduled_end,
                status
            )
            VALUES
            (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
            )
        ");

        $stmt->execute([
            uuid(),
            $organizationId,
            $customerId,
            $jobNumber,
            $title,
            $description ?: null,
            $priority,
            $estimatedAmount,
            $scheduledStart ?: null,
            $scheduledEnd ?: null,
            $jobStatus
        ]);

        flash(
            'success',
            "Job {$jobNumber} created successfully."
        );

        redirect_to('jobs.php');
    }


    if ($action === 'status') {

        $jobId = filter_input(
            INPUT_POST,
            'job_id',
            FILTER_VALIDATE_INT
        );

        $status = $_POST['status'] ?? '';

        $allowed = [
            'draft',
            'scheduled',
            'in_progress',
            'completed',
            'cancelled'
        ];

        if (!$jobId || !in_array($status, $allowed, true)) {
            flash('error', 'Invalid job update.');
            redirect_to('jobs.php');
        }

        $completedAt = $status === 'completed'
            ? date('Y-m-d H:i:s')
            : null;

        $stmt = db()->prepare("
            UPDATE jobs
            SET
                status = ?,
                completed_at = ?
            WHERE id = ?
              AND organization_id = ?
        ");

        $stmt->execute([
            $status,
            $completedAt,
            $jobId,
            $organizationId
        ]);

        if ($status === 'completed') {
            emit_event($organizationId, 'job.completed', $jobId);
        }

        flash('success', 'Job status updated.');

        redirect_to('jobs.php');
    }
}


$stmt = db()->prepare("
    SELECT
        j.*,

        c.first_name,
        c.last_name,
        c.email,

        CONCAT(c.first_name, ' ', c.last_name) AS customer_name

    FROM jobs j

    INNER JOIN customers c
        ON c.id = j.customer_id

    WHERE j.organization_id = ?

    ORDER BY j.created_at DESC

    LIMIT 100
");

$stmt->execute([$organizationId]);

$jobs = $stmt->fetchAll();


$customerStmt = db()->prepare("
    SELECT id, first_name, last_name
    FROM customers
    WHERE organization_id = ?
      AND status = 'active'
    ORDER BY first_name ASC
");

$customerStmt->execute([$organizationId]);

$customers = $customerStmt->fetchAll();

require_once __DIR__ . '/../app/views/partials/header.php';

?>

<div class="app-shell">

    <?php require __DIR__ . '/../app/views/partials/sidebar.php'; ?>

    <main class="app-main">

        <?php require __DIR__ . '/../app/views/partials/topbar.php'; ?>

        <section class="page-content">

            <div class="page-heading">

                <div>

                    <span class="eyebrow">
                        OPERATIONS
                    </span>

                    <h1>Jobs</h1>

                    <p>
                        Track work from creation to completion.
                    </p>

                </div>

                <button
                    class="btn btn-primary"
                    data-modal-open="jobModal"
                >
                    <i class="fa-solid fa-plus"></i>
                    New job
                </button>

            </div>


            <div class="job-grid">

                <?php if (!$jobs): ?>

                    <div class="glass-panel empty-state full">

                        <div class="empty-icon">
                            <i class="fa-solid fa-briefcase"></i>
                        </div>

                        <h3>Your operation starts here</h3>

                        <p>
                            Create a job and connect it to a customer.
                        </p>

                        <button
                            class="btn btn-primary"
                            data-modal-open="jobModal"
                        >
                            Create your first job
                        </button>

                    </div>

                <?php else: ?>

                    <?php foreach ($jobs as $job): ?>

                        <article class="job-card glass-panel">

                            <div class="job-card-top">

                                <span class="reference">
                                    <?= e($job['job_number']) ?>
                                </span>

                                <span class="
                                    status-pill
                                    <?= e($job['status']) ?>
                                ">
                                    <?= e(
                                        ucwords(
                                            str_replace(
                                                '_',
                                                ' ',
                                                $job['status']
                                            )
                                        )
                                    ) ?>
                                </span>

                            </div>


                            <h3>
                                <?= e($job['title']) ?>
                            </h3>


                            <p class="job-description">

                                <?= e(
                                    $job['description']
                                    ?: 'No description provided.'
                                ) ?>

                            </p>


                            <div class="job-customer">

                                <div class="avatar small">

                                    <?= e(
                                        strtoupper(
                                            substr($job['first_name'], 0, 1) .
                                            substr($job['last_name'], 0, 1)
                                        )
                                    ) ?>

                                </div>

                                <span>
                                    <?= e($job['customer_name']) ?>
                                </span>

                            </div>


                            <div class="job-card-footer">

                                <div>

                                    <small>Estimated value</small>

                                    <strong>
                                        ₦<?= number_format(
                                            (float) $job['estimated_amount'],
                                            2
                                        ) ?>
                                    </strong>

                                </div>


                                <form method="POST">

                                    <?= csrf_field() ?>

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="status"
                                    >

                                    <input
                                        type="hidden"
                                        name="job_id"
                                        value="<?= (int) $job['id'] ?>"
                                    >

                                    <select
                                        name="status"
                                        class="mini-select"
                                        onchange="this.form.submit()"
                                    >

                                        <?php

                                        $statuses = [
                                            'draft',
                                            'scheduled',
                                            'in_progress',
                                            'completed',
                                            'cancelled'
                                        ];

                                        ?>

                                        <?php foreach ($statuses as $status): ?>

                                            <option
                                                value="<?= e($status) ?>"
                                                <?= $job['status'] === $status
                                                    ? 'selected'
                                                    : '' ?>
                                            >

                                                <?= e(
                                                    ucwords(
                                                        str_replace(
                                                            '_',
                                                            ' ',
                                                            $status
                                                        )
                                                    )
                                                ) ?>

                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                </form>

                            </div>

                        </article>

                    <?php endforeach; ?>

                <?php endif; ?>

            </div>

        </section>

    </main>

</div>

<!-- JOB MODAL -->

<div
    class="modal-backdrop"
    id="jobModal"
    aria-hidden="true"
>

    <div class="modal">

        <div class="modal-header">

            <div>

                <span class="eyebrow">
                    OPERATIONS
                </span>

                <h2>Create job</h2>

            </div>

            <button
                class="modal-close"
                data-modal-close
            >
                <i class="fa-solid fa-xmark"></i>
            </button>

        </div>


        <form method="POST">

            <?= csrf_field() ?>

            <input
                type="hidden"
                name="action"
                value="create"
            >


            <div class="form-grid">

                <div class="form-group full">

                    <label>Customer</label>

                    <select
                        name="customer_id"
                        required
                    >

                        <option value="">
                            Select customer
                        </option>

                        <?php foreach ($customers as $customer): ?>

                            <option value="<?= (int) $customer['id'] ?>">

                                <?= e(
                                    $customer['first_name'] .
                                    ' ' .
                                    $customer['last_name']
                                ) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="form-group full">

                    <label>Job title</label>

                    <input
                        type="text"
                        name="title"
                        required
                        placeholder="Website redesign"
                    >

                </div>


                <div class="form-group">

                    <label>Priority</label>

                    <select name="priority">

                        <option value="low">Low</option>
                        <option value="normal" selected>Normal</option>
                        <option value="high">High</option>
                        <option value="urgent">Urgent</option>

                    </select>

                </div>

                <div class="form-group">

                    <label>Start</label>

                    <input
                        type="datetime-local"
                        name="scheduled_start"
                    >

                </div>

                <div class="form-group">

                    <label>End</label>

                    <input
                        type="datetime-local"
                        name="scheduled_end"
                    >

                </div>


                <div class="form-group">

                    <label>Estimated value</label>

                    <input
                        type="number"
                        name="estimated_amount"
                        min="0"
                        step="0.01"
                        placeholder="0.00"
                    >

                </div>


                <div class="form-group full">

                    <label>Description</label>

                    <textarea
                        name="description"
                        rows="4"
                        placeholder="What needs to be done?"
                    ></textarea>

                </div>

            </div>


            <div class="modal-actions">

                <button
                    type="button"
                    class="btn btn-secondary"
                    data-modal-close
                >
                    Cancel
                </button>

                <button
                    class="btn btn-primary"
                    type="submit"
                >
                    Create job
                </button>

            </div>

        </form>

    </div>

</div>

<script src="js/app.js"></script>

<?php require_once __DIR__ . '/../app/views/partials/footer.php'; ?>