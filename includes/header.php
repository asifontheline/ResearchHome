<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

// Public-visitor traffic only — not the admin's own logged-in usage.
if (!current_user()) {
    record_page_view();
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= isset($pageTitle) ? h($pageTitle) . ' · ' : '' ?>ResHub</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>%F0%9F%94%AD</text></svg>">
<link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<header class="site-header">
  <a class="brand" href="/index.php">🔭 ResHub</a>
  <form class="search-form" action="/index.php" method="get">
    <input type="text" name="q" placeholder="Search title, authors, abstract, notes…" value="<?= h($_GET['q'] ?? '') ?>">
    <button type="submit">Search</button>
  </form>
  <div class="header-right">
    <?php $summary = get_catalog_summary(); ?>
    <table class="summary-box">
      <tr><td>Items</td><td><?= number_format($summary['total_items']) ?></td></tr>
      <tr><td>Tags</td><td><?= number_format($summary['total_tags']) ?></td></tr>
      <tr><td>Sources</td><td><?= number_format($summary['total_sources']) ?></td></tr>
      <?php if ($summary['last_run']): ?>
        <tr><td>Last harvest (UTC)</td><td><?= h(substr($summary['last_run']['started_at'], 5, 11)) ?></td></tr>
      <?php endif; ?>
    </table>
    <nav>
      <a href="/index.php">Home</a>
      <a href="/activity.php">Activity</a>
      <a href="/tags.php">Tags</a>
      <a href="/credits.php">Credits</a>
      <a href="/about.php">About</a>
      <?php if (current_user()): ?>
        <a href="/harvest_log.php">Harvest log</a>
        <a href="/seeds.php">Seeds</a>
        <a href="/add.php">+ Add</a>
        <a href="/logout.php">Log out</a>
      <?php endif; ?>
      <?php /* No public "Log in" link — this is a single-admin site, not a
               multi-user app; showing it to visitors implied accounts exist.
               /login.php still works directly for the admin. */ ?>
    </nav>
  </div>
</header>
<main class="container">
