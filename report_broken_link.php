<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/harvester.php'; // check_url_status()
// Public on purpose — any reader can flag a dead link, no login, no GitHub
// issue. Fully automatic: this script re-verifies the URL itself before
// acting, rather than trusting the report blindly (a single click
// shouldn't be able to delete an item that's actually fine — abuse or a
// reader's own transient/regional connectivity issue, not a dead link).

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

$id = (int)($_POST['id'] ?? 0);
$stmt = db()->prepare('SELECT id, url FROM items WHERE id = ?');
$stmt->execute([$id]);
$item = $stmt->fetch();

if ($item) {
    $code = check_url_status($item['url']);
    $isDead = $code === null || $code >= 400;
    if ($isDead) {
        delete_item($id);
        header('Location: /index.php?reported=removed');
        exit;
    }
}

header('Location: ' . ($item ? "/item.php?id={$id}&reported=stillup" : '/index.php'));
exit;
