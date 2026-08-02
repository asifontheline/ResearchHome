<?php
// One-off admin worker: drains the retag_backlog_batch() / classify_zero_tag_backlog()
// backlog to completion, independent of the harvest.php cron cadence
// (harvest only gives these a small slice of its own 15-minute budget once
// per slot, far too slow for a ~2,000-item one-time cleanup pass).
//
// Each request does exactly ONE small batch, renders results immediately,
// and self-refreshes after a short pause -- deliberately NOT one long
// server-side loop. An earlier version looped for up to 45s per request
// without sending any bytes back in the meantime, and the idle connection
// got killed (ERR_QUIC_PROTOCOL_ERROR) by the browser/host proxy before it
// ever got a response. Short, fast, frequent requests avoid that entirely.
//
// Leave the tab open and it drains the whole backlog on its own. Safe to
// stop anytime (each batch commits as it goes, via the same cursors
// harvest.php's background job uses) and safe to re-open later. Delete
// this file once the backlog is clear -- it's not linked from anywhere.
//
// Can also be driven unattended by a real cron job instead of a browser
// tab -- a tab left open kept going idle/getting throttled/backgrounded,
// which is real progress lost to nothing but browser behavior. Passing
// ?key=<TAG_CLEANUP_KEY> (set in config.php) skips the login-session
// requirement so `curl`/`wget` from cron can call this directly. Without
// a valid key, falls back to the normal admin-login requirement exactly
// as before.
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/harvester.php';

$providedKey = $_GET['key'] ?? '';
$hasValidKey = defined('TAG_CLEANUP_KEY') && TAG_CLEANUP_KEY !== '' && hash_equals(TAG_CLEANUP_KEY, $providedKey);
if (!$hasValidKey) {
    require_login();
}

// Short enough that this request finishes fast regardless -- these two
// batches are already individually time-budgeted, this is just a ceiling.
$sliceDeadline = microtime(true) + 12;

$retag = retag_backlog_batch(500, $sliceDeadline);
$zeroTag = classify_zero_tag_backlog(15, $sliceDeadline);

$allDone = $retag['done'] && $zeroTag['done'];
$remaining = (int) db()->query(
    'SELECT COUNT(*) FROM items i LEFT JOIN item_tags it ON it.item_id = i.id WHERE it.item_id IS NULL'
)->fetchColumn();

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Tag cleanup worker</title>
<?php if (!$allDone): ?><meta http-equiv="refresh" content="2"><?php endif; ?>
</head>
<body style="font-family: monospace; padding: 1.5rem; line-height: 1.6;">
<h3><?= $allDone ? 'DONE — backlog fully cleared.' : 'Working — page refreshes every 2s…' ?></h3>
<p>This pass: checked <?= $retag['checked'] ?> item(s) for retagging (<?= $retag['retagged'] ?> changed, <?= $retag['rescued'] ?> rescued from zero tags),
checked <?= $zeroTag['checked'] ?> zero-tag item(s) via body-text scan (<?= $zeroTag['tagged'] ?> tagged).</p>
<p>Zero-tag items remaining right now: <strong><?= $remaining ?></strong></p>
<?php if ($allDone): ?>
<p>Nothing left to process. Safe to delete this file now.</p>
<?php else: ?>
<p>Leave this tab open — it'll keep going on its own.</p>
<?php endif; ?>
</body>
</html>
