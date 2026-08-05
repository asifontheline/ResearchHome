<?php
// Tag/URL validation entrypoint: php validator.php
// Deliberately decoupled from harvest.php -- its own cron entry, own
// run-lock, own harvest_log run_type ('validator'). See run_validator() in
// includes/harvester.php for why this isn't embedded in the content
// harvest run (it used to be, and shared deadline with discovery/crawl
// steps that could starve it out entirely as the seed list grew).
//
// Also reachable over the web as an admin-only "run validator now" trigger
// (POST only, login-gated), same pattern as harvest.php/discover.php.
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

// CLI (cron) gets the full run budget (VALIDATOR_MAX_RUNTIME_MINUTES, 4);
// the admin web trigger stays short since a human is waiting on it.
set_time_limit($isCli ? VALIDATOR_MAX_RUNTIME_MINUTES * 60 : 60);

$result = run_validator();

if ($isCli) {
    printf(
        "Validator done. Links checked: %d. Items removed: %d. Errors: %d.\n",
        $result['links_checked'] ?? 0, $result['items_removed'] ?? 0, count($result['errors'] ?? [])
    );
    foreach ($result['errors'] ?? [] as $e) {
        fwrite(STDERR, $e . "\n");
    }
} else {
    echo json_encode($result);
}
