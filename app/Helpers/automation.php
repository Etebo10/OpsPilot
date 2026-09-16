<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| AUTOMATION ENGINE
|--------------------------------------------------------------------------
|
| Plain-English map of how this works, start to finish:
|
|   1. Something happens in the app (a payment comes in, an invoice
|      goes overdue). That code calls emit_event().
|   2. emit_event() looks up any rules the business has turned on for
|      that event ("when an invoice is overdue, notify staff") and
|      drops one row into jobs_queue for each matching rule.
|   3. A worker (workers/worker.php, run automatically every minute by
|      Windows Task Scheduler or cron) calls claim_next_job() to grab
|      one job at a time -- built so that even if you accidentally ran
|      two workers at once, they could never grab the SAME job.
|   4. The worker calls run_action() to actually do the thing (send an
|      email, notify staff, etc.).
|   5. If it worked: complete_job(). If it failed: fail_job(), which
|      either schedules a retry a bit later (with a longer wait each
|      time) or, after enough failures, marks it "dead_letter" -- still
|      visible on the admin page, still fixable, just not retrying
|      itself into infinity.
|
*/


/**
 * Step 1: something happened. Tell the automation system, and it
 * figures out what (if anything) should happen next.
 *
 * $entityId should be the id of the thing this event is about (an
 * invoice id, a job id, a customer id) -- it's what makes re-emitting
 * the same event for the same thing harmless instead of duplicating
 * work.
 */
function emit_event(int $organizationId, string $event, int $entityId, array $context = []): void
{
    $stmt = db()->prepare(
        'SELECT * FROM automation_rules
         WHERE organization_id = ? AND event = ? AND is_active = 1'
    );
    $stmt->execute([$organizationId, $event]);
    $rules = $stmt->fetchAll();

    foreach ($rules as $rule) {
        $idempotencyKey = 'rule:' . $rule['id'] . ':entity:' . $entityId;

        enqueue_job(
            organizationId: $organizationId,
            jobType: $rule['action'],
            payload: array_merge($context, [
                'event' => $event,
                'entity_id' => $entityId,
                'action_config' => json_decode((string) $rule['action_config'], true) ?? [],
            ]),
            idempotencyKey: $idempotencyKey,
            automationRuleId: (int) $rule['id']
        );
    }
}


/**
 * Add one piece of durable work to the queue. Safe to call more than
 * once for the "same" piece of work -- the second call is silently
 * ignored, because idempotency_key is a UNIQUE column.
 */
function enqueue_job(
    int $organizationId,
    string $jobType,
    array $payload,
    string $idempotencyKey,
    ?int $automationRuleId = null,
    int $delaySeconds = 0
): bool {
    try {
        $stmt = db()->prepare(
            'INSERT INTO jobs_queue
                (organization_id, automation_rule_id, job_type, payload,
                 idempotency_key, available_at)
             VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))'
        );
        $stmt->execute([
            $organizationId,
            $automationRuleId,
            $jobType,
            json_encode($payload),
            $idempotencyKey,
            $delaySeconds,
        ]);

        return true;

    } catch (PDOException $e) {
        // Error code 23000 = duplicate key -- this exact job already
        // exists. That's not a bug, it's the idempotency guarantee
        // working as intended, so we just quietly do nothing.
        if ($e->getCode() === '23000') {
            return false;
        }

        throw $e;
    }
}


/**
 * Step 3: grab exactly one job to work on, in a way that's safe even
 * if multiple workers run at the same moment. Two workers calling
 * this at the same instant can never end up with the same job --
 * one of them will simply get null back and move on.
 */
function claim_next_job(string $workerId): ?array
{
    $pdo = db();

    for ($attempt = 0; $attempt < 5; $attempt++) {

        $candidate = $pdo->prepare(
            'SELECT id FROM jobs_queue
             WHERE status = \'pending\' AND available_at <= NOW()
             ORDER BY id ASC
             LIMIT 1'
        );
        $candidate->execute();
        $row = $candidate->fetch();

        if (!$row) {
            return null; // nothing waiting to be done right now
        }

        // The WHERE status = 'pending' here is the whole trick: if
        // another worker already claimed this exact row a moment ago,
        // this UPDATE matches zero rows and changes nothing.
        $claim = $pdo->prepare(
            'UPDATE jobs_queue
             SET status = \'processing\', locked_by = ?, locked_at = NOW(), attempts = attempts + 1
             WHERE id = ? AND status = \'pending\''
        );
        $claim->execute([$workerId, $row['id']]);

        if ($claim->rowCount() === 1) {
            $full = $pdo->prepare('SELECT * FROM jobs_queue WHERE id = ?');
            $full->execute([$row['id']]);
            return $full->fetch();
        }

        // Someone else claimed it between our SELECT and UPDATE --
        // loop around and try the next candidate instead.
    }

    return null;
}


function complete_job(array $job, string $resultMessage = 'OK'): void
{
    db()->prepare(
        'UPDATE jobs_queue SET status = \'completed\' WHERE id = ?'
    )->execute([$job['id']]);

    record_automation_run($job, 'success', $resultMessage);
}


/**
 * A job failed. Either give it another chance a bit later (waiting
 * longer each time -- 30s, then 2min, then 8min, and so on) or, once
 * it's used up all its attempts, mark it dead_letter so a human can
 * look at it, rather than retrying forever.
 */
function fail_job(array $job, string $errorMessage): void
{
    $attempts = (int) $job['attempts'];
    $maxAttempts = (int) $job['max_attempts'];

    if ($attempts >= $maxAttempts) {
        db()->prepare(
            'UPDATE jobs_queue SET status = \'dead_letter\', last_error = ? WHERE id = ?'
        )->execute([$errorMessage, $job['id']]);

        record_automation_run($job, 'failed', $errorMessage);
        return;
    }

    // Exponential backoff: 30s, 120s, 480s, 1920s... capped at 1 hour.
    $backoffSeconds = min(3600, 30 * (2 ** ($attempts - 1)));

    db()->prepare(
        'UPDATE jobs_queue
         SET status = \'pending\', last_error = ?, locked_by = NULL, locked_at = NULL,
             available_at = DATE_ADD(NOW(), INTERVAL ? SECOND)
         WHERE id = ?'
    )->execute([$errorMessage, $backoffSeconds, $job['id']]);

    record_automation_run($job, 'failed', $errorMessage);
}


function record_automation_run(array $job, string $status, string $message): void
{
    $payload = json_decode((string) $job['payload'], true) ?? [];

    db()->prepare(
        'INSERT INTO automation_runs
            (organization_id, job_queue_id, event, action, attempt_number, status, result_message)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $job['organization_id'],
        $job['id'],
        $payload['event'] ?? null,
        $job['job_type'],
        $job['attempts'],
        $status,
        $message,
    ]);
}


/**
 * Human recovery: put a dead_letter (or any failed) job back to
 * pending with a clean slate, so it gets picked up again on the
 * next worker run.
 */
function retry_job(int $organizationId, int $jobId): void
{
    $stmt = db()->prepare(
        'UPDATE jobs_queue
         SET status = \'pending\', attempts = 0, last_error = NULL,
             locked_by = NULL, locked_at = NULL, available_at = NOW()
         WHERE id = ? AND organization_id = ?'
    );
    $stmt->execute([$jobId, $organizationId]);

    log_action(action: 'automation.job_retried', entityType: 'jobs_queue', entityId: $jobId);
}


/**
 * Step 4: actually do the thing. Add a new `case` here whenever you
 * add a new action -- everything else in the queue/retry machinery
 * above stays exactly the same.
 */
function run_action(array $job): string
{
    $payload = json_decode((string) $job['payload'], true) ?? [];
    $organizationId = (int) $job['organization_id'];

    switch ($job['job_type']) {

        case 'notify_staff':
            return action_notify_staff($organizationId, $payload);

        case 'send_email':
            return action_send_email($organizationId, $payload);

        case 'send_message':
            return action_send_message($organizationId, $payload);

        default:
            // An action type we don't know how to run yet (this is
            // expected for actions like "Send WhatsApp message" until
            // that adapter phase is built). Throwing here means it
            // will retry and eventually land in dead_letter instead of
            // silently vanishing.
            throw new RuntimeException('No handler implemented yet for action "' . $job['job_type'] . '".');
    }
}


function action_notify_staff(int $organizationId, array $payload): string
{
    $message = $payload['action_config']['message']
        ?? build_default_notification_message($payload);

    db()->prepare(
        'INSERT INTO staff_notifications (organization_id, message) VALUES (?, ?)'
    )->execute([$organizationId, $message]);

    return 'Notification created: ' . $message;
}


function build_default_notification_message(array $payload): string
{
    $event = $payload['event'] ?? 'event';
    $entityId = $payload['entity_id'] ?? '?';

    $friendly = [
        'invoice.overdue' => 'Invoice #%s is overdue.',
        'payment.received' => 'A payment was received on invoice #%s.',
        'invoice.created' => 'Invoice #%s was created.',
        'invoice.paid' => 'Invoice #%s is now fully paid.',
        'customer.created' => 'New customer added (#%s).',
        'job.completed' => 'Job #%s was marked completed.',
        'inbox.new_message' => 'New message in conversation #%s.',
    ];

    $template = $friendly[$event] ?? ($event . ' (#%s)');

    return sprintf($template, $entityId);
}


/**
 * Sends an email through Mailgun (app/Helpers/email.php). Before
 * Phase 7 this used PHP's mail(), which almost never works on
 * XAMPP -- that was fine for proving the retry/dead-letter
 * machinery, but this is the real version.
 */
function action_send_email(int $organizationId, array $payload): string
{
    $to = $payload['action_config']['to'] ?? null;
    $subject = $payload['action_config']['subject'] ?? 'Notification from OpsPilot';
    $body = $payload['action_config']['body'] ?? build_default_notification_message($payload);

    if (!$to) {
        throw new RuntimeException('No recipient email configured for this rule.');
    }

    $providerMessageId = send_via_mailgun($organizationId, $to, $subject, $body);

    return 'Email sent to ' . $to . ' (Mailgun id: ' . $providerMessageId . ')';
}
