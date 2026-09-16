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
        require_permission('invoices.manage');

        $customerId = filter_input(INPUT_POST, 'customer_id', FILTER_VALIDATE_INT);
        $jobId = filter_input(INPUT_POST, 'job_id', FILTER_VALIDATE_INT) ?: null;
        $dueDate = trim($_POST['due_date'] ?? '') ?: null;
        $taxAmount = (float) ($_POST['tax'] ?? 0);
        $notes = trim($_POST['notes'] ?? '') ?: null;

        // Line items arrive as parallel arrays: item_description[],
        // item_quantity[], item_unit_price[] -- one entry per row the
        // user added in the form.
        $descriptions = $_POST['item_description'] ?? [];
        $quantities = $_POST['item_quantity'] ?? [];
        $unitPrices = $_POST['item_unit_price'] ?? [];

        $items = [];
        foreach ($descriptions as $index => $description) {
            $items[] = [
                'description' => $description,
                'quantity' => $quantities[$index] ?? 0,
                'unit_price' => $unitPrices[$index] ?? 0,
            ];
        }

        if (!$customerId) {
            flash('error', 'Choose a customer.');
            redirect_to('invoices.php');
        }

        try {
            $invoice = create_invoice(
                organizationId: $organizationId,
                customerId: $customerId,
                jobId: $jobId,
                items: $items,
                taxAmount: $taxAmount,
                dueDate: $dueDate,
                notes: $notes,
                actorUserId: (int) $user['id']
            );

            flash('success', "Invoice {$invoice['invoice_number']} created successfully.");
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }

        redirect_to('invoices.php');
    }

    if ($action === 'status') {
        require_permission('invoices.manage');

        $invoiceId = filter_input(INPUT_POST, 'invoice_id', FILTER_VALIDATE_INT);
        $status = $_POST['status'] ?? '';

        if (!$invoiceId) {
            flash('error', 'Invalid invoice.');
            redirect_to('invoices.php');
        }

        try {
            update_invoice_status_manual($organizationId, $invoiceId, $status, (int) $user['id']);
            flash('success', 'Invoice status updated.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }

        redirect_to('invoices.php');
    }
}

// Auto-flip anything past its due date to "overdue" -- a real
// scheduled version of this arrives in the Automation Workers phase.
mark_overdue_invoices($organizationId);

$stmt = db()->prepare(
    'SELECT i.*, CONCAT(c.first_name, \' \', c.last_name) AS customer_name
     FROM invoices i
     INNER JOIN customers c ON c.id = i.customer_id
     WHERE i.organization_id = ?
     ORDER BY i.created_at DESC LIMIT 100'
);
$stmt->execute([$organizationId]);
$invoices = $stmt->fetchAll();

$customerStmt = db()->prepare(
    'SELECT id, first_name, last_name FROM customers
     WHERE organization_id = ? AND status = \'active\'
     ORDER BY first_name ASC'
);
$customerStmt->execute([$organizationId]);
$customers = $customerStmt->fetchAll();

$jobStmt = db()->prepare(
    "SELECT id, job_number, title FROM jobs
     WHERE organization_id = ? AND status != 'cancelled'
     ORDER BY created_at DESC LIMIT 200"
);
$jobStmt->execute([$organizationId]);
$jobs = $jobStmt->fetchAll();

// Statuses a person is allowed to *pick*. Paid / partially paid / overdue
// are calculated, not chosen -- see app/Helpers/invoices.php.
$manualStatuses = ['draft', 'sent', 'cancelled'];
$computedStatuses = ['partially_paid', 'paid', 'overdue'];

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
                    <span class="eyebrow">FINANCE</span>
                    <h1>Invoices</h1>
                    <p>Track what customers owe and what has been paid.</p>
                </div>
                <?php if (can($user, 'invoices.manage')): ?>
                    <button class="btn btn-primary" data-modal-open="invoiceModal">
                        <i class="fa-solid fa-plus"></i>
                        Create invoice
                    </button>
                <?php endif; ?>
            </div>

            <div class="glass-panel table-panel">
                <?php if (!$invoices): ?>
                    <div class="empty-state">
                        <div class="empty-icon">
                            <i class="fa-solid fa-file-invoice-dollar"></i>
                        </div>
                        <h3>No invoices yet</h3>
                        <p>Create your first invoice to start tracking revenue.</p>
                    </div>
                <?php else: ?>
                    <div class="table-scroll">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Invoice</th>
                                    <th>Customer</th>
                                    <th>Due</th>
                                    <th>Total</th>
                                    <th>Balance</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($invoices as $invoice): ?>
                                    <?php $balance = (float) $invoice['total_amount'] - (float) $invoice['amount_paid']; ?>
                                    <tr>
                                        <td><?= e($invoice['invoice_number']) ?></td>
                                        <td><?= e($invoice['customer_name']) ?></td>
                                        <td><?= $invoice['due_date'] ? e(date('M j, Y', strtotime($invoice['due_date']))) : '—' ?></td>
                                        <td><strong>₦<?= e(number_format((float) $invoice['total_amount'], 2)) ?></strong></td>
                                        <td>₦<?= e(number_format($balance, 2)) ?></td>
                                        <td>
                                            <?php if (in_array($invoice['status'], $computedStatuses, true)): ?>
                                                <span class="badge badge-<?= e($invoice['status']) ?>">
                                                    <?= e(ucfirst(str_replace('_', ' ', $invoice['status']))) ?>
                                                </span>
                                            <?php elseif (can($user, 'invoices.manage')): ?>
                                                <form method="POST" class="inline-form">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="status">
                                                    <input type="hidden" name="invoice_id" value="<?= (int) $invoice['id'] ?>">
                                                    <select name="status" class="mini-select" onchange="this.form.submit()">
                                                        <?php foreach ($manualStatuses as $status): ?>
                                                            <option value="<?= e($status) ?>" <?= $invoice['status'] === $status ? 'selected' : '' ?>>
                                                                <?= e(ucfirst($status)) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </form>
                                            <?php else: ?>
                                                <span class="badge"><?= e(ucfirst($invoice['status'])) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a class="btn btn-ghost btn-sm" href="invoice.php?id=<?= (int) $invoice['id'] ?>">
                                                View
                                            </a>
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

<div class="modal-backdrop" id="invoiceModal" aria-hidden="true">
    <div class="modal modal-lg">
        <div class="modal-header">
            <div>
                <span class="eyebrow">FINANCE</span>
                <h2>Create invoice</h2>
            </div>
            <button class="modal-close" data-modal-close type="button">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" id="invoiceForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">

            <div class="form-grid">
                <div class="form-group full">
                    <label>Customer</label>
                    <select name="customer_id" required>
                        <option value="">Select customer</option>
                        <?php foreach ($customers as $customer): ?>
                            <option value="<?= (int) $customer['id'] ?>">
                                <?= e($customer['first_name'] . ' ' . $customer['last_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ($jobs): ?>
                <div class="form-group full">
                    <label>Link to a job (optional)</label>
                    <select name="job_id">
                        <option value="">No linked job</option>
                        <?php foreach ($jobs as $job): ?>
                            <option value="<?= (int) $job['id'] ?>">
                                <?= e($job['job_number'] . ' — ' . $job['title']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="form-group">
                    <label>Due date (optional)</label>
                    <input type="date" name="due_date">
                </div>
            </div>

            <hr>

            <div id="lineItems">
                <div class="line-item-row">
                    <input type="text" name="item_description[]" placeholder="Description" required>
                    <input type="number" name="item_quantity[]" placeholder="Qty" min="0.01" step="0.01" value="1" required class="qty-input">
                    <input type="number" name="item_unit_price[]" placeholder="Unit price" min="0" step="0.01" required class="price-input">
                    <span class="line-total">₦0.00</span>
                    <button type="button" class="btn btn-ghost btn-sm remove-line" title="Remove line">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
            </div>

            <button type="button" class="btn btn-ghost btn-sm" id="addLineItem">
                <i class="fa-solid fa-plus"></i> Add line
            </button>

            <div class="form-grid" style="margin-top: 16px;">
                <div class="form-group">
                    <label>Tax amount</label>
                    <input type="number" name="tax" id="taxInput" min="0" step="0.01" value="0">
                </div>
                <div class="form-group full">
                    <label>Notes (optional)</label>
                    <input type="text" name="notes" placeholder="Payment terms, thank-you note, etc.">
                </div>
            </div>

            <div class="invoice-totals">
                <div><span>Subtotal</span><strong id="subtotalDisplay">₦0.00</strong></div>
                <div><span>Tax</span><strong id="taxDisplay">₦0.00</strong></div>
                <div class="grand-total"><span>Total</span><strong id="totalDisplay">₦0.00</strong></div>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-file-invoice-dollar"></i>
                    Create invoice
                </button>
            </div>
        </form>
    </div>
</div>

<script src="js/app.js"></script>
<script>
// Small, self-contained script just for the invoice line-item rows.
// Doesn't touch anything in app.js -- safe to sit alongside it.
(function () {
    const container = document.getElementById('lineItems');
    const addButton = document.getElementById('addLineItem');
    const taxInput = document.getElementById('taxInput');

    const rowTemplate = () => {
        const row = document.createElement('div');
        row.className = 'line-item-row';
        row.innerHTML = `
            <input type="text" name="item_description[]" placeholder="Description" required>
            <input type="number" name="item_quantity[]" placeholder="Qty" min="0.01" step="0.01" value="1" required class="qty-input">
            <input type="number" name="item_unit_price[]" placeholder="Unit price" min="0" step="0.01" required class="price-input">
            <span class="line-total">₦0.00</span>
            <button type="button" class="btn btn-ghost btn-sm remove-line" title="Remove line">
                <i class="fa-solid fa-xmark"></i>
            </button>
        `;
        return row;
    };

    function formatNaira(value) {
        return '₦' + value.toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function recalculate() {
        let subtotal = 0;

        container.querySelectorAll('.line-item-row').forEach((row) => {
            const qty = parseFloat(row.querySelector('.qty-input').value) || 0;
            const price = parseFloat(row.querySelector('.price-input').value) || 0;
            const total = qty * price;
            row.querySelector('.line-total').textContent = formatNaira(total);
            subtotal += total;
        });

        const tax = parseFloat(taxInput.value) || 0;

        document.getElementById('subtotalDisplay').textContent = formatNaira(subtotal);
        document.getElementById('taxDisplay').textContent = formatNaira(tax);
        document.getElementById('totalDisplay').textContent = formatNaira(subtotal + tax);
    }

    addButton.addEventListener('click', () => {
        container.appendChild(rowTemplate());
        recalculate();
    });

    container.addEventListener('click', (event) => {
        const removeBtn = event.target.closest('.remove-line');
        if (!removeBtn) return;

        const rows = container.querySelectorAll('.line-item-row');
        if (rows.length > 1) {
            removeBtn.closest('.line-item-row').remove();
            recalculate();
        }
    });

    container.addEventListener('input', recalculate);
    taxInput.addEventListener('input', recalculate);

    recalculate();
})();
</script>
<?php require_once __DIR__ . '/../app/views/partials/footer.php'; ?>
