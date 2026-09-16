<?php

declare(strict_types=1);

/*
| Also CLI-only, also run by Task Scheduler/cron -- every 15 or 30
| minutes is plenty (invoices don't need to be checked every second).
| This is deliberately a SEPARATE script from worker.php: this one
| decides WHAT needs attention across every business; worker.php just
| processes whatever's already sitting in the queue. Keeping them
| separate means a slow scan here never blocks the queue from being
| worked on, and vice versa.
*/

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

require_once __DIR__ . '/../app/bootstrap.php';

$lockFile = sys_get_temp_dir() . '/opspilot_overdue_check.lock';
$lockHandle = fopen($lockFile, 'c');

if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo "Another overdue-check run is already in progress. Exiting.\n";
    exit(0);
}

echo "Starting overdue-invoice check at " . date('Y-m-d H:i:s') . "\n";

$organizations = db()->query(
    'SELECT id, name FROM organizations WHERE is_active = 1'
)->fetchAll();

$totalFlipped = 0;

foreach ($organizations as $organization) {
    $flipped = mark_overdue_invoices((int) $organization['id']);

    if ($flipped > 0) {
        echo "  {$organization['name']}: {$flipped} invoice(s) marked overdue.\n";
        $totalFlipped += $flipped;
    }
}

echo "Done. {$totalFlipped} invoice(s) flipped to overdue across "
    . count($organizations) . " organization(s).\n";

flock($lockHandle, LOCK_UN);
fclose($lockHandle);
