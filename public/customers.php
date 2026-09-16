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

        $firstName = trim($_POST['first_name'] ?? '');
        $lastName  = trim($_POST['last_name'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $phone     = trim($_POST['phone'] ?? '');
        $company   = trim($_POST['company_name'] ?? '');
        $notes     = trim($_POST['notes'] ?? '');

        if ($firstName === '' || $lastName === '') {
            flash('error', 'First name and last name are required.');
            redirect_to('customers.php');
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Please enter a valid email address.');
            redirect_to('customers.php');
        }

        $publicId = uuid();

        $stmt = db()->prepare("
            INSERT INTO customers
            (
                public_id,
                organization_id,
                first_name,
                last_name,
                email,
                phone,
                company_name,
                notes,
                source,
                last_activity_at
            )
            VALUES
            (
                :public_id,
                :organization_id,
                :first_name,
                :last_name,
                :email,
                :phone,
                :company_name,
                :notes,
                'manual',
                NOW()
            )
        ");

        $stmt->execute([
            ':public_id'       => $publicId,
            ':organization_id' => $organizationId,
            ':first_name'      => $firstName,
            ':last_name'       => $lastName,
            ':email'           => $email ?: null,
            ':phone'           => $phone ?: null,
            ':company_name'    => $company ?: null,
            ':notes'           => $notes ?: null
        ]);

        emit_event($organizationId, 'customer.created', (int) db()->lastInsertId(), [
            'first_name' => $firstName,
            'last_name' => $lastName,
        ]);

        flash('success', 'Customer added successfully.');
        redirect_to('customers.php');
    }

    if ($action === 'archive') {

        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

        if (!$id) {
            flash('error', 'Invalid customer.');
            redirect_to('customers.php');
        }

        $stmt = db()->prepare("
            UPDATE customers
            SET status = 'archived'
            WHERE id = ?
              AND organization_id = ?
        ");

        $stmt->execute([
            $id,
            $organizationId
        ]);

        flash('success', 'Customer archived.');
        redirect_to('customers.php');
    }
}


$search = trim($_GET['search'] ?? '');

$sql = "
    SELECT
        c.*,
        (
            SELECT COUNT(*)
            FROM jobs j
            WHERE j.customer_id = c.id
              AND j.organization_id = c.organization_id
        ) AS job_count,

        (
            SELECT COUNT(*)
            FROM invoices i
            WHERE i.customer_id = c.id
              AND i.organization_id = c.organization_id
        ) AS invoice_count

                ,(
                        SELECT COALESCE(SUM(i.amount_paid), 0)
                        FROM invoices i
                        WHERE i.customer_id = c.id
                            AND i.organization_id = c.organization_id
                ) AS total_spent

    FROM customers c

    WHERE c.organization_id = ?
      AND c.status != 'archived'
";

$params = [$organizationId];

if ($search !== '') {

    $sql .= "
        AND (
            c.first_name LIKE ?
            OR c.last_name LIKE ?
            OR c.email LIKE ?
            OR c.phone LIKE ?
            OR c.company_name LIKE ?
        )
    ";

    $term = '%' . $search . '%';

    array_push(
        $params,
        $term,
        $term,
        $term,
        $term,
        $term
    );
}

$sql .= " ORDER BY c.created_at DESC LIMIT 100";

$stmt = db()->prepare($sql);
$stmt->execute($params);

$customers = $stmt->fetchAll();

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
                        CUSTOMER RELATIONSHIP MANAGEMENT
                    </span>

                    <h1>Customers</h1>

                    <p>
                        One intelligent record for every person your business serves.
                    </p>
                </div>

                <button
                    class="btn btn-primary"
                    data-modal-open="customerModal"
                >
                    <i class="fa-solid fa-plus"></i>
                    Add customer
                </button>

            </div>


            <div class="toolbar glass-panel">

                <form method="GET" class="search-box">

                    <i class="fa-solid fa-magnifying-glass"></i>

                    <input
                        type="search"
                        name="search"
                        value="<?= e($search) ?>"
                        placeholder="Search customers..."
                    >

                </form>

            </div>


            <div class="glass-panel table-panel">

                <div class="table-header">

                    <div>
                        <strong>
                            <?= number_format(count($customers)) ?>
                        </strong>

                        <span>customers</span>
                    </div>

                </div>

                <?php if (!$customers): ?>

                    <div class="empty-state">

                        <div class="empty-icon">
                            <i class="fa-solid fa-users"></i>
                        </div>

                        <h3>No customers yet</h3>

                        <p>
                            Add your first customer and start building
                            your business intelligence layer.
                        </p>

                        <button
                            class="btn btn-primary"
                            data-modal-open="customerModal"
                        >
                            Add your first customer
                        </button>

                    </div>

                <?php else: ?>

                    <div class="table-scroll">

                        <table class="data-table">

                            <thead>
                                <tr>
                                    <th>Customer</th>
                                    <th>Contact</th>
                                    <th>Jobs</th>
                                    <th>Invoices</th>
                                    <th>Lifetime value</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>

                            <tbody>

                            <?php foreach ($customers as $customer): ?>

                                <tr>

                                    <td>

                                        <div class="person-cell">

                                            <div class="avatar">
                                                <?= e(
                                                    strtoupper(
                                                        substr($customer['first_name'], 0, 1) .
                                                        substr($customer['last_name'], 0, 1)
                                                    )
                                                ) ?>
                                            </div>

                                            <div>
                                                <strong>
                                                    <?= e(
                                                        $customer['first_name'] .
                                                        ' ' .
                                                        $customer['last_name']
                                                    ) ?>
                                                </strong>

                                                <?php if ($customer['company_name']): ?>

                                                    <small>
                                                        <?= e($customer['company_name']) ?>
                                                    </small>

                                                <?php endif; ?>

                                            </div>

                                        </div>

                                    </td>

                                    <td>

                                        <div class="contact-stack">

                                            <?php if ($customer['email']): ?>

                                                <span>
                                                    <?= e($customer['email']) ?>
                                                </span>

                                            <?php endif; ?>

                                            <?php if ($customer['phone']): ?>

                                                <span>
                                                    <?= e($customer['phone']) ?>
                                                </span>

                                            <?php endif; ?>

                                        </div>

                                    </td>

                                    <td>
                                        <?= (int) $customer['job_count'] ?>
                                    </td>

                                    <td>
                                        <?= (int) $customer['invoice_count'] ?>
                                    </td>

                                    <td>
                                        ₦<?= number_format(
                                            (float) $customer['total_spent'],
                                            2
                                        ) ?>
                                    </td>

                                    <td>
                                        <span class="status-pill success">
                                            Active
                                        </span>
                                    </td>

                                    <td>

                                        <form method="POST">

                                            <?= csrf_field() ?>

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="archive"
                                            >

                                            <input
                                                type="hidden"
                                                name="id"
                                                value="<?= (int) $customer['id'] ?>"
                                            >

                                            <button
                                                class="icon-button"
                                                title="Archive"
                                                onclick="return confirm('Archive this customer?')"
                                            >
                                                <i class="fa-solid fa-ellipsis"></i>
                                            </button>

                                        </form>

                                    </td>

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

<!-- CUSTOMER MODAL -->

<div
    class="modal-backdrop"
    id="customerModal"
    aria-hidden="true"
>

    <div class="modal">

        <div class="modal-header">

            <div>

                <span class="eyebrow">
                    NEW RECORD
                </span>

                <h2>Add customer</h2>

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

                <div class="form-group">

                    <label>First name</label>

                    <input
                        type="text"
                        name="first_name"
                        required
                        placeholder="John"
                    >

                </div>


                <div class="form-group">

                    <label>Last name</label>

                    <input
                        type="text"
                        name="last_name"
                        required
                        placeholder="Doe"
                    >

                </div>


                <div class="form-group">

                    <label>Email</label>

                    <input
                        type="email"
                        name="email"
                        placeholder="john@example.com"
                    >

                </div>


                <div class="form-group">

                    <label>Phone</label>

                    <input
                        type="tel"
                        name="phone"
                        placeholder="+234..."
                    >

                </div>


                <div class="form-group full">

                    <label>Company</label>

                    <input
                        type="text"
                        name="company_name"
                        placeholder="Company name"
                    >

                </div>


                <div class="form-group full">

                    <label>Notes</label>

                    <textarea
                        name="notes"
                        rows="4"
                        placeholder="Anything your team should know..."
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
                    type="submit"
                    class="btn btn-primary"
                >
                    Create customer
                </button>

            </div>

        </form>

    </div>

</div>

<script src="js/app.js"></script>

<?php require_once __DIR__ . '/../app/views/partials/footer.php'; ?>