<?php
// Source-discovery entrypoint: php discover.php
// Deliberately decoupled from harvest.php (content harvest) — new sources
// don't appear often enough to need the same cadence, and internally
// discover_sources_openalex() already self-throttles to once per 24h
// regardless of how often this is invoked. See DESIGN.md §4.2.1.
//
// Also reachable over the web as an admin-only "run discovery now" trigger
// (POST only, login-gated), same pattern as harvest.php.
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

// CLI (cron) gets the full run budget (DISCOVERY_MAX_RUNTIME_MINUTES, 29);
// the admin web trigger stays short since a human is waiting on it.
set_time_limit($isCli ? DISCOVERY_MAX_RUNTIME_MINUTES * 60 : 60);

$result = run_source_discovery();

if ($isCli) {
    printf("Discovery done. New seeds proposed: %d. Errors: %d.\n", $result['new_seeds_discovered'], count($result['errors']));
    foreach ($result['errors'] as $e) {
        fwrite(STDERR, $e . "\n");
    }
} else {
    echo json_encode($result);
}
