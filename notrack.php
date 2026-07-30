<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

$action = $_GET['action'] ?? 'on';
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? null) == 443;

if ($action === 'off') {
    setcookie('reshub_notrack', '', [
        'expires' => time() - 3600, 'path' => '/', 'secure' => $isHttps, 'httponly' => true, 'samesite' => 'Lax',
    ]);
    $message = 'Tracking re-enabled on this device.';
} else {
    setcookie('reshub_notrack', '1', [
        'expires' => time() + 10 * 365 * 24 * 3600, 'path' => '/', 'secure' => $isHttps, 'httponly' => true, 'samesite' => 'Lax',
    ]);
    $message = "This device is now excluded from visitor tracking (page_views/geo_cache) — set once, sticks around for years, doesn't require staying logged in.";
}

$pageTitle = 'Device tracking';
require __DIR__ . '/includes/header.php';
?>

<h1>Device tracking</h1>
<p class="muted" style="max-width: 60ch;"><?= h($message) ?></p>
<p class="muted" style="max-width: 60ch;">
  This only affects this specific browser/device — repeat on each device you want
  excluded (e.g. each phone, each browser on your laptop).
</p>
<p>
  <?php if ($action === 'off'): ?>
    <a href="/notrack.php?action=on">Exclude this device again</a>
  <?php else: ?>
    <a href="/notrack.php?action=off">Re-enable tracking on this device</a>
  <?php endif; ?>
  &middot; <a href="/harvest_log.php">Back to harvest log</a>
</p>

<?php require __DIR__ . '/includes/footer.php'; ?>
