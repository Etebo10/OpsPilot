<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| QUEUE WORKER
|--------------------------------------------------------------------------
|
| This file is NOT a web page -- it lives outside public/ on purpose,
| so nobody can trigger it just by visiting a URL. It's meant to be
| run by Windows Task Scheduler (or cron on Mac/Linux) every minute.
| See the instructions you were given for exactly how to set that up.
|
| Each run: grab up to 50 jobs, one at a time, run each one, then
| exit. Running it again a minute later picks up wherever this run
| left off. This "batch and exit" shape is much easier to schedule
| reliably on Windows than trying to keep a script running forever.
*/

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

require_once __DIR__ . '/../app/bootstrap.php';

// ------------------------------------------------------------
// Prevent two copies of this worker running at the same time
// (e.g. if one run takes longer than a minute and Task Scheduler
// starts a second one before the first finishes).
// ------------------------------------------------------------

$lockFile = sys_get_temp_dir() . '/opspilot_worker.lock';
$lockHandle = fopen($lockFile, 'c');

if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo "Another worker is already running. Exiting.\n";
    exit(0);
}

$workerId = gethostname() . '-' . getmypid();
$maxJobsPerRun = 50;
$processed = 0;

echo "[$workerId] Starting run at " . date('Y-m-d H:i:s') . "\n";

while ($processed < $maxJobsPerRun) {
    $job = claim_next_job($workerId);

    if (!$job) {
        break; // nothing left waiting right now
    }

    $processed++;

    echo "[$workerId] Job #{$job['id']} ({$job['job_type']}) — ";

    try {
        $result = run_action($job);
        complete_job($job, $result);
        echo "done: $result\n";

    } catch (Throwable $e) {
        fail_job($job, $e->getMessage());
        echo "FAILED: " . $e->getMessage() . "\n";
    }
}

echo "[$workerId] Finished. Processed $processed job(s).\n";

flock($lockHandle, LOCK_UN);
fclose($lockHandle);
