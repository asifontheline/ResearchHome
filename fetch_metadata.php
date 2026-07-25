<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json');

if (!current_user()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$url = trim($_GET['url'] ?? '');
if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid URL']);
    exit;
}

$meta = fetch_metadata_for_url($url);
echo json_encode($meta);
