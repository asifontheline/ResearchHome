<?php
// One-time helper to create (or reset) the single admin login.
// Run it via cPanel > Terminal or "Setup PHP App" cron-style once, then DELETE this file.
//   php create_admin.php <username> <password>
require_once __DIR__ . '/includes/db.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script must be run from the command line, not the browser.');
}

[$script, $username, $password] = array_pad($argv, 3, null);
if (!$username || !$password) {
    fwrite(STDERR, "Usage: php create_admin.php <username> <password>\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$pdo = db();
$stmt = $pdo->prepare(
    'INSERT INTO users (username, password_hash) VALUES (?, ?)
     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)'
);
$stmt->execute([$username, $hash]);

echo "Admin user '{$username}' created/updated.\n";
