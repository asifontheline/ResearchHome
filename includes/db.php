<?php
require_once __DIR__ . '/../config.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        // DB_SOCKET (optional) is the faster, more reliable path when the app
        // and MySQL run on the same server — set it in config.php to something
        // like /run/mysqld/mysqld.sock if your host documents one. Falls back
        // to DB_HOST/TCP when not set (e.g. local dev, or a remote DB host).
        $dsn = (defined('DB_SOCKET') && DB_SOCKET)
            ? 'mysql:unix_socket=' . DB_SOCKET . ';dbname=' . DB_NAME . ';charset=utf8mb4'
            : 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}
