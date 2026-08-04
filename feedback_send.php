<?php
// Backend for the floating feedback widget (see includes/footer.php). Sends
// a plain email to FEEDBACK_EMAIL -- deliberately does NOT create a GitHub
// issue, unlike emailing FEEDBACK_EMAIL directly (see process_feedback_emails()
// in includes/harvester.php, which polls that same inbox and would
// otherwise sweep these up too; it explicitly skips anything with this
// endpoint's subject prefix). No login required -- this is for any visitor.
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/harvester.php'; // send_email()

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

if (!defined('FEEDBACK_EMAIL') || !FEEDBACK_EMAIL) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Feedback isn\'t configured on this site yet.']);
    exit;
}

$message = trim($_POST['message'] ?? '');
if ($message === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Message is required.']);
    exit;
}
$message = mb_strimwidth($message, 0, 5000, "\n…(truncated)");

$replyTo = trim($_POST['email'] ?? '');
if ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
    $replyTo = ''; // ignore an invalid address rather than hard-failing the whole submission
}

$page = parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_PATH) ?: 'unknown page';
$body = "Page: {$page}\n" . ($replyTo !== '' ? "Reply-to: {$replyTo}\n" : "(no reply address given)\n") . "\n{$message}\n";

$sent = send_email(FEEDBACK_EMAIL, '[Widget Feedback] New message from reshub.in', $body, $replyTo ?: null);

if (!$sent) {
    http_response_code(500);
}
echo json_encode(['ok' => $sent]);
