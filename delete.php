<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

$id = (int)($_POST['id'] ?? 0);
if ($id > 0) {
    db()->prepare('DELETE FROM items WHERE id = ?')->execute([$id]);
}
header('Location: /index.php');
exit;
