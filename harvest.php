<?php
// Cron entrypoint: php harvest.php
// Also reachable over the web as an admin-only "run harvest now" trigger
// (POST only, login-gated) for on-demand runs during setup/testing.
require_once __DIR__ . '/includes/harvester.php';

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    require_once __DIR__ . '/includes/auth.php';
    header('Content-Type: application/json');
    if (!current_user()) {
        http_response_code(401);
        echo json_encode(['error' => 'Not authenticated']);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }
}

// CLI (cron) gets the full run budget (HARVEST_MAX_RUNTIME_MINUTES, 59) —
// run_content_harvest()'s own soft time-budget checks are what actually
// stop it early; this is just the outer safety net. The admin "run now"
// web trigger stays short — a human is waiting on that request, a
// multi-hour browser hang isn't useful even if the host would allow it.
set_time_limit($isCli ? HARVEST_MAX_RUNTIME_MINUTES * 60 : 240);

$result = run_content_harvest();

// Rides along on this cron job instead of needing its own — self-expiring,
// no-ops once MONITOR_EMAIL is unset or the window has closed. See
// run_monitor_check() in includes/harvester.php.
if ($isCli) {
    run_monitor_check();
}

if ($isCli) {
    printf(
        "Harvest done. Items added: %d. Links discovered: %d. Errors: %d.\n",
        $result['items_added'], $result['links_discovered'], count($result['errors'])
    );
    foreach ($result['errors'] as $e) {
        fwrite(STDERR, $e . "\n");
    }
} else {
    echo json_encode($result);
}
