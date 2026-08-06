<?php
// Continuous validator daemon: NOT cron-driven. Start once via SSH and
// leave it running:
//   nohup php validator_daemon.php >> logs/validator_daemon.log 2>&1 &
// Stop it with: kill <pid>   (graceful -- finishes the current iteration
// and releases its lock) or `kill -9 <pid>` if it's ever truly stuck.
//
// Loops forever: one short, bounded slice of work, then a few seconds of
// sleep, repeat. This replaces validator.php's cron entry entirely --
// remove that cron job once this is running (see README/DESIGN for the
// old cron-based setup this supersedes). validator.php itself is
// untouched and still works for the admin "Run validator now" button.
//
// Why this doesn't just re-introduce the same hang risk as the old
// cron-triggered runs: each iteration is wrapped in a HARD interrupt via
// pcntl_alarm() + a SIGALRM handler that throws, not just the soft
// time_budget_exceeded() check every batch function already does.
// Confirmed on production: a blocking call (almost certainly DNS
// resolution not respecting curl's own CURLOPT_CONNECTTIMEOUT/TIMEOUT)
// could freeze an entire run past its deadline with no way to recover --
// neither curl's timeout options nor PHP's set_time_limit() can preempt
// an in-flight blocking syscall, only a real signal can. Falls back to
// best-effort (the existing soft deadline only) if the `pcntl` extension
// isn't loaded, same exposure the old cron-based validator.php had.
require_once __DIR__ . '/includes/harvester.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

set_time_limit(0); // meant to run forever; no PHP-level cap to fight

const ITERATION_HARD_TIMEOUT_SECONDS = 30;
const SLEEP_SECONDS = 5;
const FLUSH_INTERVAL_SECONDS = 300; // 5 min -- matches the old cron cadence

$hasPcntl = extension_loaded('pcntl');
$shuttingDown = false;

if ($hasPcntl) {
    pcntl_async_signals(true);
    pcntl_signal(SIGALRM, function () {
        throw new RuntimeException('Iteration hard-timeout (SIGALRM) -- a blocking call did not respect its own timeout.');
    });
    pcntl_signal(SIGTERM, function () use (&$shuttingDown) {
        $shuttingDown = true;
    });
    pcntl_signal(SIGINT, function () use (&$shuttingDown) {
        $shuttingDown = true;
    });
}

// Single-instance guard, same lock as validator.php/run_validator() --
// refuses to start a second daemon (or collide with a still-active
// cron-triggered validator.php run) rather than risk two instances
// working the same random-sampled batches simultaneously. Refreshed every
// iteration below (a heartbeat) so it doesn't go stale while genuinely
// still running -- that also means mark_stale_runs_as_crashed() and any
// future "is the daemon alive" check can just look at this timestamp.
if (!acquire_run_lock('validator', 1)) {
    fwrite(STDERR, "Another validator process (daemon or cron-triggered validator.php) appears to be active. Not starting a second one.\n");
    exit(1);
}

ensure_harvest_log_validator_run_type();
ensure_harvest_log_links_validated_column();
ensure_items_validation_group_column();

printf(
    "%s Validator daemon started (pid %d). pcntl hard-interrupt: %s.\n",
    date('Y-m-d H:i:s'), getmypid(), $hasPcntl ? 'available' : 'NOT available -- best-effort only'
);

function validator_daemon_fresh_agg(): array {
    return [
        'links_checked' => 0, 'links_validated' => 0, 'items_removed' => 0,
        'retag_checked' => 0, 'retagged' => 0, 'rescued' => 0,
        'zero_tag_checked' => 0, 'zero_tag_tagged' => 0, 'zero_tag_fallback' => 0,
        'general_checked' => 0, 'general_upgraded' => 0,
        'language_checked' => 0, 'language_detected' => 0,
        'groups_backfilled' => 0,
    ];
}

$agg = validator_daemon_fresh_agg();
$lastFlush = time();
$windowStart = date('Y-m-d H:i:s');

function validator_daemon_flush(array &$agg, string &$windowStart): void {
    db()->prepare(
        "INSERT INTO harvest_log (started_at, finished_at, run_type, links_checked, links_validated, items_removed, errors, detail)
         VALUES (?, NOW(), 'validator', ?, ?, ?, 0, ?)"
    )->execute([
        $windowStart, $agg['links_checked'], $agg['links_validated'], $agg['items_removed'],
        "Daemon summary since {$windowStart}: reviewed {$agg['retag_checked']} existing item(s), "
            . "retagged {$agg['retagged']}, rescued {$agg['rescued']} newly-zero-tag; "
            . "zero-tag scan checked {$agg['zero_tag_checked']}, tagged {$agg['zero_tag_tagged']}, "
            . "fell back to General for {$agg['zero_tag_fallback']}; "
            . "General-reclassify checked {$agg['general_checked']}, upgraded {$agg['general_upgraded']}; "
            . "language backfill checked {$agg['language_checked']}, detected {$agg['language_detected']}; "
            . "validation-group backfill assigned {$agg['groups_backfilled']}.",
    ]);
    printf("%s Flushed summary to harvest_log.\n", date('Y-m-d H:i:s'));
    $agg = validator_daemon_fresh_agg();
    $windowStart = date('Y-m-d H:i:s');
}

while (!$shuttingDown) {
    try {
        if ($hasPcntl) pcntl_alarm(ITERATION_HARD_TIMEOUT_SECONDS);

        // Soft deadline still checked inside each batch function too --
        // belt and suspenders. Small per-iteration limits since this runs
        // constantly rather than once per 5 minutes; small-and-frequent
        // adds up to the same throughput with far less exposed per hang.
        $iterationDeadline = microtime(true) + ITERATION_HARD_TIMEOUT_SECONDS - 3;

        // Catches up any items still missing a validation_group (pre-
        // existing catalog, before this column existed) -- cheap, pure-DB,
        // fine to run every iteration; naturally becomes a no-op once done.
        $groupBackfill = backfill_validation_groups_batch(200, $iterationDeadline);
        $agg['groups_backfilled'] += $groupBackfill['assigned'];

        $linkCheck = check_links_batch(5, $iterationDeadline);
        $agg['links_checked'] += $linkCheck['checked'];
        $agg['links_validated'] += $linkCheck['validated'];
        $agg['items_removed'] += $linkCheck['removed'];

        $retag = retag_backlog_batch(50, $iterationDeadline);
        $agg['retag_checked'] += $retag['checked'];
        $agg['retagged'] += $retag['retagged'];
        $agg['rescued'] += $retag['rescued'];

        $zeroTag = classify_zero_tag_backlog(5, $iterationDeadline);
        $agg['zero_tag_checked'] += $zeroTag['checked'];
        $agg['zero_tag_tagged'] += $zeroTag['tagged'];
        $agg['zero_tag_fallback'] += $zeroTag['fallback'];

        $general = reclassify_general_backlog(5, $iterationDeadline);
        $agg['general_checked'] += $general['checked'];
        $agg['general_upgraded'] += $general['upgraded'];

        $language = backfill_language_batch(5, $iterationDeadline);
        $agg['language_checked'] += $language['checked'];
        $agg['language_detected'] += $language['detected'];

        if ($hasPcntl) pcntl_alarm(0); // disarm -- this iteration finished cleanly
    } catch (Throwable $e) {
        if ($hasPcntl) pcntl_alarm(0);
        printf("%s Iteration failed: %s -- continuing.\n", date('Y-m-d H:i:s'), $e->getMessage());
        // The alarm (if that's what interrupted us) could have fired
        // mid-query and left the connection in a bad state -- reconnect
        // before the next iteration tries to use it.
        try { db(true); } catch (Throwable $e2) {}
    }

    // Heartbeat: keeps the single-instance lock fresh without needing a
    // full re-acquire, so a genuinely-alive daemon never gets treated as
    // stale by mark_stale_runs_as_crashed() or a future health check.
    set_setting('validator_lock_started_at', date('Y-m-d H:i:s'));

    if (time() - $lastFlush >= FLUSH_INTERVAL_SECONDS) {
        validator_daemon_flush($agg, $windowStart);
        $lastFlush = time();
    }

    sleep(SLEEP_SECONDS);
}

if (array_sum($agg) > 0) {
    validator_daemon_flush($agg, $windowStart);
}
release_run_lock('validator');
printf("%s Validator daemon stopped (SIGTERM/SIGINT).\n", date('Y-m-d H:i:s'));
