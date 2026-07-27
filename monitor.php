<?php
// Standalone monitoring entrypoint, for if a separate cron job is ever set
// up for it (php monitor.php). Not required for monitoring to work — it
// also runs automatically as part of every harvest.php invocation (see
// run_monitor_check() call there), riding along on the cron job that's
// already configured rather than needing a new one.
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

set_time_limit(30);
$result = run_monitor_check();

if ($isCli) {
    echo $result['window_open']
        ? "Monitor report sent: " . var_export($result['sent'], true) . " (window open until {$result['expires_at']})\n"
        : "Monitoring window closed (expired {$result['expires_at']}).\n";
} else {
    echo json_encode($result);
}
