<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| INVOICES & PAYMENTS (shared logic)
|--------------------------------------------------------------------------
|
| Plain-English idea: this file is the ONLY place allowed to change an
| invoice's totals or decide whether it's paid. Every screen that touches
| money -- the manual "record a payment" page today, and the Paystack
| webhook we build next -- calls the SAME function here instead of each
| doing its own math. That's what stops the two places from disagreeing
| with each other.
|
*/


/**
 * Create an invoice with one or more line items in a single transaction.
 *
 * @param array<int, array{description:string, quantity:float, unit_price:float}> $items
 */
function create_invoice(
    int $organizationId,
    int $customerId,
    ?int $jobId,
    array $items,
    float $taxAmount,
    ?string $dueDate,
    ?string $notes,
    ?int $actorUserId
): array {

    if (empty($items)) {
        throw new RuntimeException('An invoice needs at least one line item.');
    }

    $cleanItems = [];
    $subtotal = 0.0;

    foreach ($items as $item) {
        $description = trim((string) ($item['description'] ?? ''));
        $quantity = (float) ($item['quantity'] ?? 0);
        $unitPrice = (float) ($item['unit_price'] ?? 0);

        if ($description === '' || $quantity <= 0 || $unitPrice < 0) {
            continue; // skip blank/incomplete rows the user didn't fill in
        }

        $lineTotal = round($quantity * $unitPrice, 2);
        $subtotal += $lineTotal;

        $cleanItems[] = [
            'description' => $description,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total' => $lineTotal,
        ];
    }

    if (empty($cleanItems)) {
        throw new RuntimeException('Enter at least one complete line item (description, quantity, and price).');
    }

    $subtotal = round($subtotal, 2);
    $taxAmount = round(max(0, $taxAmount), 2);
    $totalAmount = round($subtotal + $taxAmount, 2);

    $pdo = db();

    try {
        $pdo->beginTransaction();

        $check = $pdo->prepare(
            'SELECT id FROM customers
             WHERE id = ? AND organization_id = ? AND status = \'active\'
             LIMIT 1'
        );
        $check->execute([$customerId, $organizationId]);

        if (!$check->fetch()) {
            throw new RuntimeException('Invalid customer.');
        }

        if ($jobId !== null) {
            $jobCheck = $pdo->prepare(
                'SELECT id FROM jobs WHERE id = ? AND organization_id = ? LIMIT 1'
            );
            $jobCheck->execute([$jobId, $organizationId]);

            if (!$jobCheck->fetch()) {
                throw new RuntimeException('Invalid job.');
            }
        }

        $invoiceNumber = generate_reference('INV');

        $stmt = $pdo->prepare(
            'INSERT INTO invoices
                (organization_id, customer_id, job_id, invoice_number,
                 issue_date, due_date, subtotal, tax_amount, total_amount,
                 amount_paid, status, notes)
             VALUES (?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, 0, \'draft\', ?)'
        );
        $stmt->execute([
            $organizationId,
            $customerId,
            $jobId,
            $invoiceNumber,
            $dueDate ?: null,
            $subtotal,
            $taxAmount,
            $totalAmount,
            $notes ?: null,
        ]);

        $invoiceId = (int) $pdo->lastInsertId();

        $itemStmt = $pdo->prepare(
            'INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, total)
             VALUES (?, ?, ?, ?, ?)'
        );

        foreach ($cleanItems as $item) {
            $itemStmt->execute([
                $invoiceId,
                $item['description'],
                $item['quantity'],
                $item['unit_price'],
                $item['total'],
            ]);
        }

        $pdo->commit();

        log_action(
            action: 'invoice.created',
            entityType: 'invoice',
            entityId: $invoiceId,
            after: [
                'invoice_number' => $invoiceNumber,
                'total_amount' => $totalAmount,
                'customer_id' => $customerId,
                'line_items' => count($cleanItems),
            ]
        );

        emit_event($organizationId, 'invoice.created', $invoiceId, [
            'invoice_number' => $invoiceNumber,
            'total_amount' => $totalAmount,
        ]);

        return get_invoice($organizationId, $invoiceId);

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}


/**
 * Fetch one invoice, its line items, and its payment history --
 * always scoped to the organization, so one business can never
 * load another business's invoice by guessing an ID.
 */
function get_invoice(int $organizationId, int $invoiceId): ?array
{
    $stmt = db()->prepare(
        'SELECT i.*, CONCAT(c.first_name, \' \', c.last_name) AS customer_name
         FROM invoices i
         INNER JOIN customers c ON c.id = i.customer_id
         WHERE i.id = ? AND i.organization_id = ?
         LIMIT 1'
    );
    $stmt->execute([$invoiceId, $organizationId]);
    $invoice = $stmt->fetch();

    if (!$invoice) {
        return null;
    }

    $items = db()->prepare(
        'SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id ASC'
    );
    $items->execute([$invoiceId]);
    $invoice['items'] = $items->fetchAll();

    $payments = db()->prepare(
        'SELECT * FROM payments WHERE invoice_id = ? AND organization_id = ? ORDER BY paid_at DESC'
    );
    $payments->execute([$invoiceId, $organizationId]);
    $invoice['payments'] = $payments->fetchAll();

    return $invoice;
}


/**
 * The ONE function allowed to record a payment against an invoice.
 * Used today by the manual "record a payment" page, and will be
 * called directly by the Paystack webhook in the next phase --
 * same math, same safety checks, every time.
 *
 * $source lets us tell manual entries apart from Paystack later
 * (e.g. 'manual', 'paystack') without changing this function's shape.
 */
function apply_payment_to_invoice(
    int $organizationId,
    int $invoiceId,
    float $amount,
    string $method,
    ?string $reference,
    ?string $notes,
    ?int $actorUserId,
    string $source = 'manual'
): array {

    if ($amount <= 0) {
        throw new RuntimeException('Payment amount must be greater than zero.');
    }

    $pdo = db();

    try {
        $pdo->beginTransaction();

        // Lock the invoice row so two payments can't be recorded against
        // it at the exact same instant and both "succeed" incorrectly.
        $stmt = $pdo->prepare(
            'SELECT id, customer_id, total_amount, amount_paid, status
             FROM invoices
             WHERE id = ? AND organization_id = ?
             FOR UPDATE'
        );
        $stmt->execute([$invoiceId, $organizationId]);
        $invoice = $stmt->fetch();

        if (!$invoice) {
            throw new RuntimeException('Invoice not found.');
        }

        if ($invoice['status'] === 'cancelled') {
            throw new RuntimeException('This invoice is cancelled and cannot receive payments.');
        }

        $remaining = round((float) $invoice['total_amount'] - (float) $invoice['amount_paid'], 2);

        if ($remaining <= 0) {
            throw new RuntimeException('This invoice is already fully paid.');
        }

        if ($amount > $remaining) {
            throw new RuntimeException('Payment cannot exceed the outstanding balance.');
        }

        if ($reference !== null && $reference !== '') {
            $referenceCheck = $pdo->prepare(
                'SELECT id FROM payments WHERE organization_id = ? AND reference = ? LIMIT 1'
            );
            $referenceCheck->execute([$organizationId, $reference]);

            if ($referenceCheck->fetch()) {
                throw new RuntimeException('That payment reference has already been recorded.');
            }
        }

        $paymentPublicId = uuid();

        $payment = $pdo->prepare(
            'INSERT INTO payments
                (public_id, organization_id, invoice_id, customer_id, amount,
                 payment_method, reference, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $payment->execute([
            $paymentPublicId,
            $organizationId,
            $invoiceId,
            $invoice['customer_id'],
            $amount,
            $method,
            $reference ?: null,
            $notes ?: null,
        ]);

        $paymentId = (int) $pdo->lastInsertId();

        $newPaid = round((float) $invoice['amount_paid'] + $amount, 2);
        $newStatus = $newPaid >= (float) $invoice['total_amount'] ? 'paid' : 'partially_paid';

        $update = $pdo->prepare(
            'UPDATE invoices SET amount_paid = ?, status = ? WHERE id = ? AND organization_id = ?'
        );
        $update->execute([$newPaid, $newStatus, $invoiceId, $organizationId]);

        $customerUpdate = $pdo->prepare(
            'UPDATE customers SET total_spent = total_spent + ?, last_activity_at = NOW()
             WHERE id = ? AND organization_id = ?'
        );
        $customerUpdate->execute([$amount, $invoice['customer_id'], $organizationId]);

        $pdo->commit();

        log_action(
            action: 'payment.recorded',
            entityType: 'payment',
            entityId: $paymentId,
            after: [
                'invoice_id' => $invoiceId,
                'amount' => $amount,
                'method' => $method,
                'source' => $source,
                'new_invoice_status' => $newStatus,
            ]
        );

        emit_event($organizationId, 'payment.received', $invoiceId, [
            'amount' => $amount,
            'method' => $method,
        ]);

        if ($newStatus === 'paid') {
            emit_event($organizationId, 'invoice.paid', $invoiceId, [
                'total_amount' => (float) $invoice['total_amount'],
            ]);
        }

        return [
            'payment_id' => $paymentId,
            'invoice_id' => $invoiceId,
            'new_amount_paid' => $newPaid,
            'new_status' => $newStatus,
        ];

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}


/**
 * The only manual status changes a person is allowed to make.
 * "Partially paid", "Paid", and "Overdue" are earned, not chosen --
 * they come from real payments (above) and real due dates (below).
 */
function update_invoice_status_manual(
    int $organizationId,
    int $invoiceId,
    string $newStatus,
    ?int $actorUserId
): void {

    $allowedManualStatuses = ['draft', 'sent', 'cancelled'];

    if (!in_array($newStatus, $allowedManualStatuses, true)) {
        throw new RuntimeException(
            '"' . ucfirst(str_replace('_', ' ', $newStatus)) . '" isn\'t something you set manually -- ' .
            'it\'s calculated from real payments or the due date. Record a payment instead.'
        );
    }

    $stmt = db()->prepare(
        'SELECT status FROM invoices WHERE id = ? AND organization_id = ? LIMIT 1'
    );
    $stmt->execute([$invoiceId, $organizationId]);
    $current = $stmt->fetch();

    if (!$current) {
        throw new RuntimeException('Invoice not found.');
    }

    if (in_array($current['status'], ['paid', 'partially_paid'], true)) {
        throw new RuntimeException('This invoice already has payments recorded and cannot be moved back to ' . $newStatus . '.');
    }

    $update = db()->prepare(
        'UPDATE invoices SET status = ? WHERE id = ? AND organization_id = ?'
    );
    $update->execute([$newStatus, $invoiceId, $organizationId]);

    log_action(
        action: 'invoice.status_changed',
        entityType: 'invoice',
        entityId: $invoiceId,
        before: ['status' => $current['status']],
        after: ['status' => $newStatus]
    );
}


/**
 * Reverse money on an invoice after a Paystack refund. Mirrors
 * apply_payment_to_invoice() but subtracts instead of adds, and
 * writes to payment_refunds instead of payments so your payment
 * history stays a clean record of money actually collected.
 */
function reverse_payment_on_invoice(
    int $organizationId,
    int $invoiceId,
    int $paystackTransactionId,
    float $amount,
    ?string $reason
): void {

    if ($amount <= 0) {
        throw new RuntimeException('Refund amount must be greater than zero.');
    }

    $pdo = db();

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'SELECT id, customer_id, total_amount, amount_paid, status
             FROM invoices
             WHERE id = ? AND organization_id = ?
             FOR UPDATE'
        );
        $stmt->execute([$invoiceId, $organizationId]);
        $invoice = $stmt->fetch();

        if (!$invoice) {
            throw new RuntimeException('Invoice not found for refund.');
        }

        $newPaid = max(0, round((float) $invoice['amount_paid'] - $amount, 2));

        $newStatus = $newPaid <= 0
            ? 'sent'
            : ($newPaid >= (float) $invoice['total_amount'] ? 'paid' : 'partially_paid');

        $update = $pdo->prepare(
            'UPDATE invoices SET amount_paid = ?, status = ? WHERE id = ? AND organization_id = ?'
        );
        $update->execute([$newPaid, $newStatus, $invoiceId, $organizationId]);

        $customerUpdate = $pdo->prepare(
            'UPDATE customers SET total_spent = GREATEST(0, total_spent - ?)
             WHERE id = ? AND organization_id = ?'
        );
        $customerUpdate->execute([$amount, $invoice['customer_id'], $organizationId]);

        $refund = $pdo->prepare(
            'INSERT INTO payment_refunds
                (organization_id, invoice_id, paystack_transaction_id, amount, reason)
             VALUES (?, ?, ?, ?, ?)'
        );
        $refund->execute([$organizationId, $invoiceId, $paystackTransactionId, $amount, $reason]);

        $pdo->commit();

        log_action(
            action: 'payment.refunded',
            entityType: 'invoice',
            entityId: $invoiceId,
            after: [
                'amount' => $amount,
                'new_status' => $newStatus,
                'reason' => $reason,
            ]
        );

        emit_event($organizationId, 'payment.refunded', $invoiceId, [
            'amount' => $amount,
            'reason' => $reason,
        ]);

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}


/**
 * Flip any invoice past its due date to "overdue" automatically, and
 * tell the automation system about each one so rules (e.g. "notify
 * staff when an invoice goes overdue") can react. Called opportunely
 * when the Invoices page loads, AND on a real schedule now via
 * workers/check_overdue_invoices.php.
 */
function mark_overdue_invoices(int $organizationId): int
{
    $find = db()->prepare(
        'SELECT id FROM invoices
         WHERE organization_id = ?
           AND due_date IS NOT NULL
           AND due_date < CURDATE()
           AND status IN (\'sent\', \'partially_paid\')'
    );
    $find->execute([$organizationId]);
    $overdueIds = $find->fetchAll(PDO::FETCH_COLUMN);

    if (empty($overdueIds)) {
        return 0;
    }

    $placeholders = implode(',', array_fill(0, count($overdueIds), '?'));

    $update = db()->prepare(
        "UPDATE invoices SET status = 'overdue' WHERE id IN ($placeholders)"
    );
    $update->execute($overdueIds);

    foreach ($overdueIds as $invoiceId) {
        emit_event($organizationId, 'invoice.overdue', (int) $invoiceId);
    }

    return count($overdueIds);
}
