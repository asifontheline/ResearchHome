<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
// Public on purpose — anyone (not just the admin) can opt their own device
// out of the page-view/geolocation tracking described in the Traffic
// section of harvest_log.php.

$action = $_GET['action'] ?? 'on';

if ($action === 'off') {
    setcookie('reshub_notrack', '', [
        'expires' => time() - 3600, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax',
    ]);
    $message = 'Tracking re-enabled on this device.';
} else {
    setcookie('reshub_notrack', '1', [
        'expires' => time() + 10 * 365 * 24 * 3600, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax',
    ]);
    $message = "This device is now excluded from visitor tracking (page_views/geo_cache) — set once, sticks around for years, doesn't require staying logged in.";
}

$pageTitle = 'Opt out of tracking';
require __DIR__ . '/includes/header.php';
?>

<h1>Opt out of tracking</h1>
<p class="muted" style="max-width: 60ch;">
  ResHub logs basic page-view stats (page path, an approximate city/region/country
  from your IP, a daily-rotating anonymous hash — never your raw IP address). This
  page lets you opt this browser/device out of that entirely.
</p>
<p class="muted" style="max-width: 60ch;"><?= h($message) ?></p>
<p class="muted" style="max-width: 60ch;">
  This only affects this specific browser/device — repeat on each device you want
  excluded.
</p>
<p>
  <?php if ($action === 'off'): ?>
    <a href="/notrack.php?action=on">Opt out on this device</a>
  <?php else: ?>
    <a href="/notrack.php?action=off">Opt back in on this device</a>
  <?php endif; ?>
  &middot; <a href="/index.php">Back to home</a>
</p>

<?php require __DIR__ . '/includes/footer.php'; ?>
