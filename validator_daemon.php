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

// Every one of this script's own log lines is already timestamped
// (date('Y-m-d H:i:s') prefix on each printf), but a native PHP warning/
// notice (e.g. from a library call deep inside a batch function) would
// otherwise land in the same log via the `2>&1` redirect with no
// timestamp at all, breaking the ability to line it up against
// everything else happening at that moment. Prefixes one on, then hands
// off to PHP's normal handling (return false) so nothing about actual
// error behavior/reporting changes, just the log line's format.
set_error_handler(function (int $errno, string $errstr, string $errfile = '', int $errline = 0): bool {
    fwrite(STDERR, date('Y-m-d H:i:s') . " PHP: {$errstr} in {$errfile}:{$errline}\n");
    return false;
});

const ITERATION_HARD_TIMEOUT_SECONDS = 30;
// 0, not a few seconds -- explicit request to maximize throughput while
// working through the General backlog. Each iteration already does real
// work (multiple sub-tasks, several HTTP fetches for anything that needs
// a body) that takes real wall-clock time on its own, so removing the
// artificial gap between iterations doesn't turn this into a true busy-
// loop -- it just removes idle time that wasn't buying anything.
const SLEEP_SECONDS = 0;
const FLUSH_INTERVAL_SECONDS = 300; // 5 min -- matches the old cron cadence

// General-reclassify also gets its own dedicated 10-minute sweep, on top
// of (not instead of) the small slice it already gets every iteration --
// the per-iteration slice shares ITERATION_HARD_TIMEOUT_SECONDS with 5
// other sub-tasks, so it can only ever process a little at a time. This
// sweep gets its own uncontested budget and a much larger limit, so the
// General backlog gets one real, unhurried pass every 10 minutes instead
// of only ever seeing small competing slices.
const GENERAL_SWEEP_INTERVAL_SECONDS = 600; // 10 minutes
const GENERAL_SWEEP_LIMIT = 200;
const GENERAL_SWEEP_HARD_TIMEOUT_SECONDS = 60;

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
    fwrite(STDERR, date('Y-m-d H:i:s') . " Another validator process (daemon or cron-triggered validator.php) appears to be active. Not starting a second one.\n");
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
        'general_checked' => 0, 'general_upgraded' => 0, 'general_pruned' => 0,
        'language_checked' => 0, 'language_detected' => 0,
        'groups_backfilled' => 0,
    ];
}

$agg = validator_daemon_fresh_agg();
$lastFlush = time();
$lastGeneralSweep = time();
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
            . "General-reclassify checked {$agg['general_checked']}, upgraded {$agg['general_upgraded']}, "
            . "pruned {$agg['general_pruned']} non-articles; "
            . "language backfill checked {$agg['language_checked']}, detected {$agg['language_detected']}; "
            . "validation-group backfill assigned {$agg['groups_backfilled']}.",
    ]);
    printf("%s Flushed summary to harvest_log.\n", date('Y-m-d H:i:s'));
    $agg = validator_daemon_fresh_agg();
    $windowStart = date('Y-m-d H:i:s');
}

// Each of the 6 sub-tasks runs its own try/catch (see below) rather than
// sharing one for the whole iteration -- confirmed on production that a
// single failing sub-task (a missing-column bug in check_links_batch,
// which runs early) silently starved every task after it in the same
// iteration, including totally unrelated ones like the General-reclassify
// pass, for as long as that bug was live. This function isolates one
// sub-task so a failure in it can never block its siblings.
//
// Also arms its own fresh pcntl_alarm() per call, not just once per
// iteration -- pcntl_alarm() is one-shot (same as the underlying POSIX
// alarm() syscall it wraps): once it fires and interrupts whichever
// sub-task was running at that moment, it's spent, and every sub-task
// AFTER it in that same iteration would otherwise run with zero hang
// protection until the next loop tick re-arms it. Re-arming per sub-task
// means a hang in one sub-task can never leave its siblings unprotected.
// Trade-off: a worst case where every sub-task hangs its full budget
// could stretch one iteration well past ITERATION_HARD_TIMEOUT_SECONDS
// -- an intentional, bounded trade for "every sub-task always has real
// timeout protection" over "the iteration as a whole has a hard cap".
function validator_daemon_run_task(string $label, callable $fn, bool $hasPcntl, int $timeoutSeconds = ITERATION_HARD_TIMEOUT_SECONDS): void {
    if ($hasPcntl) pcntl_alarm($timeoutSeconds);
    try {
        $fn();
    } catch (Throwable $e) {
        printf("%s Sub-task '%s' failed: %s -- other sub-tasks this iteration still ran.\n", date('Y-m-d H:i:s'), $label, $e->getMessage());
        // Confirmed on production: a SIGALRM interrupting a query mid-flight
        // left the shared MySQL connection in a broken state ("MySQL server
        // has gone away"), which then cascaded into every OTHER sub-task
        // that same iteration failing the identical way -- and, since
        // nothing ever reconnected, every iteration after that too,
        // forever, with the process still technically "alive" the whole
        // time. This reconnect used to live in the loop's own outer catch
        // block, but isolating each sub-task into its own try/catch (see
        // this function's own comment) made that outer catch unreachable
        // for exactly this failure mode -- moved the reconnect here so it
        // still happens regardless of which sub-task hit it.
        try { db(true); } catch (Throwable $e2) {}
    } finally {
        if ($hasPcntl) pcntl_alarm(0);
    }
}

while (!$shuttingDown) {
    try {
        // Soft deadline still checked inside each batch function too --
        // belt and suspenders. Small per-iteration limits since this runs
        // constantly rather than once per 5 minutes; small-and-frequent
        // adds up to the same throughput with far less exposed per hang.
        // Each sub-task below gets its own fresh alarm window (see
        // validator_daemon_run_task()'s own comment) -- this deadline is
        // the matching soft budget for that same window, not a shared
        // iteration-wide one anymore.
        $iterationDeadline = microtime(true) + ITERATION_HARD_TIMEOUT_SECONDS - 3;

        // Catches up any items still missing a validation_group (pre-
        // existing catalog, before this column existed) -- cheap, pure-DB,
        // fine to run every iteration; naturally becomes a no-op once done.
        validator_daemon_run_task('validation-group backfill', function () use (&$agg, $iterationDeadline) {
            $r = backfill_validation_groups_batch(200, $iterationDeadline);
            $agg['groups_backfilled'] += $r['assigned'];
        }, $hasPcntl);

        // Runs 2nd (right after the near-free group backfill), not last,
        // and at a much higher limit (25, was 5) -- confirmed the General
        // backlog had grown to ~1000+ items (~25% of the catalog), and at
        // the old limit/position it was consistently starved of both
        // budget (competing against 3 other HTTP-fetching sub-tasks for
        // the same shared 30s hard-alarm window, usually last) and volume.
        // Prioritized here over link-check/zero-tag/language since an
        // over-broad 'General' tag actively hurts browsing/search *right
        // now* for ~1 in 4 items, more urgent than a link that's still
        // reachable or a language badge that's merely missing.
        validator_daemon_run_task('General-reclassify', function () use (&$agg, $iterationDeadline) {
            $r = reclassify_general_backlog(25, $iterationDeadline);
            $agg['general_checked'] += $r['checked'];
            $agg['general_upgraded'] += $r['upgraded'];
            $agg['general_pruned'] += $r['pruned'];
        }, $hasPcntl);

        validator_daemon_run_task('link-check', function () use (&$agg, $iterationDeadline) {
            $r = check_links_batch(5, $iterationDeadline);
            $agg['links_checked'] += $r['checked'];
            $agg['links_validated'] += $r['validated'];
            $agg['items_removed'] += $r['removed'];
        }, $hasPcntl);

        validator_daemon_run_task('retag', function () use (&$agg, $iterationDeadline) {
            $r = retag_backlog_batch(50, $iterationDeadline);
            $agg['retag_checked'] += $r['checked'];
            $agg['retagged'] += $r['retagged'];
            $agg['rescued'] += $r['rescued'];
        }, $hasPcntl);

        validator_daemon_run_task('zero-tag rescue', function () use (&$agg, $iterationDeadline) {
            $r = classify_zero_tag_backlog(5, $iterationDeadline);
            $agg['zero_tag_checked'] += $r['checked'];
            $agg['zero_tag_tagged'] += $r['tagged'];
            $agg['zero_tag_fallback'] += $r['fallback'];
        }, $hasPcntl);

        validator_daemon_run_task('language backfill', function () use (&$agg, $iterationDeadline) {
            $r = backfill_language_batch(5, $iterationDeadline);
            $agg['language_checked'] += $r['checked'];
            $agg['language_detected'] += $r['detected'];
        }, $hasPcntl);
    } catch (Throwable $e) {
        // Should be effectively unreachable now -- every sub-task above
        // catches its own Throwable internally (validator_daemon_run_task())
        // -- kept as a defensive backstop for anything truly outside all 6
        // sub-task calls (e.g. the $iterationDeadline expression itself).
        printf("%s Iteration failed: %s -- continuing.\n", date('Y-m-d H:i:s'), $e->getMessage());
        try { db(true); } catch (Throwable $e2) {}
    }

    // Dedicated General-reclassify sweep -- see GENERAL_SWEEP_INTERVAL_
    // SECONDS' own comment. Its own longer timeout window
    // (GENERAL_SWEEP_HARD_TIMEOUT_SECONDS), passed through to the same
    // per-call alarm handling every other sub-task uses, so it doesn't
    // compete with or get cut short by the other 5 sub-tasks.
    if (time() - $lastGeneralSweep >= GENERAL_SWEEP_INTERVAL_SECONDS) {
        $lastGeneralSweep = time();
        validator_daemon_run_task('General deep sweep (10-min)', function () use (&$agg) {
            $sweepDeadline = microtime(true) + GENERAL_SWEEP_HARD_TIMEOUT_SECONDS - 3;
            $r = reclassify_general_backlog(GENERAL_SWEEP_LIMIT, $sweepDeadline);
            $agg['general_checked'] += $r['checked'];
            $agg['general_upgraded'] += $r['upgraded'];
            $agg['general_pruned'] += $r['pruned'];
            printf(
                "%s General deep sweep: checked %d, upgraded %d, pruned %d.\n",
                date('Y-m-d H:i:s'), $r['checked'], $r['upgraded'], $r['pruned']
            );
        }, $hasPcntl, GENERAL_SWEEP_HARD_TIMEOUT_SECONDS);
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
