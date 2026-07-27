<?php
require_once __DIR__ . '/../config.php';

// Every timestamp in this app should be UTC, full stop — the alternative is
// whatever timezone the MySQL server happens to be configured with (found
// to be IST on production, the OS default on local dev, i.e. inconsistent
// per-deployment) while PHP's date()/time() are UTC (PHP's own default,
// confirmed via date_default_timezone_get()). That mismatch is exactly what
// put a wrong "sent at" timestamp in the monitoring email. Setting both
// sides to UTC explicitly, here, fixes it the same way on every deployment
// rather than special-casing one server's timezone.
date_default_timezone_set('UTC');

function create_db_connection(): PDO {
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
    // Makes NOW()/CURRENT_TIMESTAMP/CURDATE() on this connection return UTC
    // too, regardless of the MySQL server's own configured timezone.
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

/**
 * The harvester is a single long-running script (several minutes, mostly
 * spent waiting on slow outbound HTTP calls to external APIs) reusing one
 * static connection throughout. Shared-hosting MySQL often enforces a low
 * wait_timeout, so the connection can silently die between queries during a
 * long external HTTP wait — the next query then fails with "MySQL server
 * has gone away" (error 2006), which used to crash the whole run.
 *
 * A proactive ping-before-every-call was tried and reverted: running any
 * query — even a harmless SELECT 1 — resets MySQL's per-connection
 * last-insert-id tracking, silently breaking every db()->lastInsertId()
 * call site (confirmed: it started returning 0 instead of the real id,
 * which then corrupted item_tags with foreign-key-violating rows). Instead,
 * this is reactive: call db(true) from a catch block to force the *next*
 * call to reconnect, after an operation has actually failed. See the
 * per-item try/catch blocks in harvester.php's loops.
 */
function db(bool $forceReconnect = false): PDO {
    static $pdo = null;
    if ($pdo === null || $forceReconnect) {
        $pdo = create_db_connection();
    }
    return $pdo;
}
