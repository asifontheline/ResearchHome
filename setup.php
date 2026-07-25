<?php
// One-time web-based admin setup, for hosts without terminal/SSH access.
// Visit /setup.php once after importing sql/schema.sql, create your admin login,
// then DELETE this file from the server immediately.
require_once __DIR__ . '/includes/db.php';

$userCount = (int) db()->query('SELECT COUNT(*) AS c FROM users')->fetch()['c'];
$done = false;
$error = '';

if ($userCount > 0) {
    $error = 'An admin user already exists. For security this page will not create another one. Delete setup.php from the server.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($username === '' || strlen($password) < 8) {
        $error = 'Username required and password must be at least 8 characters.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        db()->prepare('INSERT INTO users (username, password_hash) VALUES (?, ?)')->execute([$username, $hash]);
        $done = true;
    }
}
?>
<!doctype html>
<html><head><meta charset="utf-8"><title>ResearchHome setup</title>
<link rel="stylesheet" href="/assets/style.css"></head>
<body><main class="container narrow">
<h1>ResearchHome setup</h1>

<?php if ($done): ?>
  <p class="success">Admin user created. <strong>Now delete setup.php from the server.</strong></p>
  <p><a href="/login.php">Go to login</a></p>
<?php else: ?>
  <?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
  <?php if ($userCount === 0): ?>
    <form method="post" class="item-form">
      <label>Admin username
        <input type="text" name="username" required autofocus>
      </label>
      <label>Admin password
        <input type="password" name="password" required minlength="8">
      </label>
      <button type="submit">Create admin</button>
    </form>
  <?php endif; ?>
<?php endif; ?>

</main></body></html>
