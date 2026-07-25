<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

if (current_user()) {
    header('Location: /index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if (attempt_login($username, $password)) {
        $next = $_POST['next'] ?? '/index.php';
        header('Location: ' . ($next !== '' ? $next : '/index.php'));
        exit;
    }
    $error = 'Invalid username or password.';
}

$pageTitle = 'Log in';
require __DIR__ . '/includes/header.php';
?>

<h1>Log in</h1>
<?php if ($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?>

<form method="post" class="item-form narrow">
  <input type="hidden" name="next" value="<?= h($_GET['next'] ?? '') ?>">
  <label>Username
    <input type="text" name="username" required autofocus>
  </label>
  <label>Password
    <input type="password" name="password" required>
  </label>
  <button type="submit">Log in</button>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
