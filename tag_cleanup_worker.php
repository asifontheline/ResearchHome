<?php
// One-off admin worker: drains the retag_backlog_batch() / classify_zero_tag_backlog()
// backlog to completion in its own request loop, independent of the
// harvest.php cron cadence (harvest only gives these a slice of its
// 15-minute budget once per slot, which was much too slow for a ~2,000-item
// one-time cleanup pass). Runs batches back-to-back for up to
// WORKER_SLICE_SECONDS per HTTP request, then self-refreshes the page to
// keep going -- leave the tab open and it drains the whole backlog on its
// own. Safe to stop anytime (each batch commits as it goes via the same
// cursors harvest.php's background job uses) and safe to re-open later
// (picks up right where it left off). Delete this file once the backlog
// is clear -- it's not linked from anywhere.
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/harvester.php';
require_login();

set_time_limit(0);

// Comfortably under every shared-hosting execution-time cap this app has
// run into so far (harvest itself budgets 14 minutes, but that's a cron
// context with no client waiting on the response -- this keeps a browser
// tab responsive by returning well before any host-side timeout would).
const WORKER_SLICE_SECONDS = 45;

$sliceDeadline = microtime(true) + WORKER_SLICE_SECONDS;

$retagged = 0;
$rescued = 0;
$zeroTagged = 0;
$retagChecked = 0;
$zeroChecked = 0;
$retagDone = false;
$zeroDone = false;

while (!time_budget_exceeded($sliceDeadline)) {
    $retag = retag_backlog_batch(500, $sliceDeadline);
    $retagged += $retag['retagged'];
    $rescued += $retag['rescued'];
    $retagChecked += $retag['checked'];
    $retagDone = $retag['done'];

    if (time_budget_exceeded($sliceDeadline)) break;

    $zeroTag = classify_zero_tag_backlog(15, $sliceDeadline);
    $zeroTagged += $zeroTag['tagged'];
    $zeroChecked += $zeroTag['checked'];
    $zeroDone = $zeroTag['done'];

    if ($retag['checked'] === 0 && $zeroTag['checked'] === 0) break; // both queries came back empty -- nothing left at all
}

$allDone = $retagDone && $zeroDone;
$remaining = (int) db()->query(
    'SELECT COUNT(*) FROM items i LEFT JOIN item_tags it ON it.item_id = i.id WHERE it.item_id IS NULL'
)->fetchColumn();

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Tag cleanup worker</title>
<?php if (!$allDone): ?><meta http-equiv="refresh" content="1"><?php endif; ?>
</head>
<body style="font-family: monospace; padding: 1.5rem; line-height: 1.6;">
<h3><?= $allDone ? 'DONE — backlog fully cleared.' : 'Working — page will auto-refresh…' ?></h3>
<p>This pass: checked <?= $retagChecked ?> item(s) for retagging (<?= $retagged ?> changed, <?= $rescued ?> rescued from zero tags),
checked <?= $zeroChecked ?> zero-tag item(s) via body-text scan (<?= $zeroTagged ?> tagged).</p>
<p>Zero-tag items remaining right now: <strong><?= $remaining ?></strong></p>
<?php if ($allDone): ?>
<p>Nothing left to process. Safe to delete this file now.</p>
<?php else: ?>
<p>Leave this tab open — it'll keep going on its own.</p>
<?php endif; ?>
</body>
</html>
