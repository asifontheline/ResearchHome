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

set_time_limit(240); // stay well under typical shared-hosting cron/script limits

$result = run_content_harvest();

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
