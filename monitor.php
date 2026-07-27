<?php
// Monitoring entrypoint: php monitor.php
// Run hourly via its own mPanel cron job. Sends a full status digest to
// MONITOR_EMAIL every run (catalog totals, recent harvest_log rows, any
// stuck/errored runs) — not alert-only, by explicit request.
//
// Self-expiring: the window is set once (see MONITOR_WINDOW_HOURS below)
// starting from this file's first run, tracked in the settings table so it
// survives across cron invocations. Once the window closes, this sends one
// final "monitoring stopped" email so silence afterward reads as "expired
// as expected" rather than "something broke and reports stopped coming."
// To restart monitoring later, clear the 'monitor_expires_at' row from the
// settings table (or just re-deploy this file) and it begins a fresh window.
require_once __DIR__ . '/includes/harvester.php';

const MONITOR_WINDOW_HOURS = 8;

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

$expiresAt = get_setting('monitor_expires_at');
if (!$expiresAt) {
    $expiresAt = date('Y-m-d H:i:s', strtotime('+' . MONITOR_WINDOW_HOURS . ' hours'));
    set_setting('monitor_expires_at', $expiresAt);
}

$windowOpen = time() < strtotime($expiresAt);
$alreadyNotifiedClose = get_setting('monitor_close_notified') === '1';

$sent = false;
if ($windowOpen) {
    $sent = send_monitor_report();
} elseif (!$alreadyNotifiedClose) {
    // Window just closed — send exactly one final notice, not one per hour forever.
    if (defined('MONITOR_EMAIL') && MONITOR_EMAIL) {
        $fromDomain = defined('CONTACT_EMAIL') && str_contains(CONTACT_EMAIL, '@')
            ? substr(CONTACT_EMAIL, strpos(CONTACT_EMAIL, '@') + 1) : 'localhost';
        $headers = "From: ResHub Monitor <noreply@{$fromDomain}>\r\nContent-Type: text/plain; charset=UTF-8";
        $body = "The " . MONITOR_WINDOW_HOURS . "-hour monitoring window (started around "
            . date('Y-m-d H:i:s', strtotime($expiresAt) - MONITOR_WINDOW_HOURS * 3600)
            . ") has ended. No further automated status emails will be sent.\n\n"
            . "This is expected, not an error. To resume monitoring, clear the "
            . "'monitor_expires_at' setting or ask for it to be restarted.";
        $sent = mail(MONITOR_EMAIL, 'ResHub monitoring window ended', $body, $headers);
    }
    set_setting('monitor_close_notified', '1');
}

if ($isCli) {
    echo $windowOpen
        ? "Monitor report sent: " . var_export($sent, true) . " (window open until {$expiresAt})\n"
        : "Monitoring window closed (expired {$expiresAt}). " . ($sent ? "Close notice sent.\n" : "Already notified previously; no email sent.\n");
} else {
    echo json_encode(['sent' => $sent, 'window_open' => $windowOpen, 'expires_at' => $expiresAt]);
}
