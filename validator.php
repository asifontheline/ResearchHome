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
    // count_real_errors(), not a bare count() -- the informational
    // "Validator: checked N, tagged N, ..." summary line always appears in
    // $result['errors'] (that's just where it's stashed to reach the log),
    // and a bare count() would report "Errors: 1" on every fully
    // successful run. Matches what harvest_log already shows in the admin
    // panel, which does use the filtered count.
    printf(
        "Validator done. Links checked: %d. Items removed: %d. Errors: %d.\n",
        $result['links_checked'] ?? 0, $result['items_removed'] ?? 0, count_real_errors($result['errors'] ?? [])
    );
    foreach ($result['errors'] ?? [] as $e) {
        fwrite(STDERR, $e . "\n");
    }
} else {
    echo json_encode($result);
}
