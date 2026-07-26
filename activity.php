<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$activity = get_harvest_activity_by_source(30);
$runs = db()->query('SELECT * FROM harvest_log ORDER BY started_at DESC LIMIT 20')->fetchAll();

$pageTitle = 'Activity';
require __DIR__ . '/includes/header.php';
?>

<p class="project-description">
  ResHub (Research Hub) automatically discovers and catalogs freely available research — papers,
  patents, and articles — from arXiv, Crossref, PubMed, OpenAlex, and more, several times an hour.
  Nothing here is copied; every item is metadata plus a link back to its original source. This is a
  dynamic website that discovers newly available information and adds it every hour — keep checking
  in and discover more!
</p>

<h1>Items added, last 30 days</h1>
<?= render_activity_chart($activity) ?>

<h2>Recent harvest runs</h2>
<table class="seed-table">
  <thead><tr><th>Type</th><th>Started</th><th>Finished</th><th>Items added</th><th>Links discovered</th><th>Items removed (dead links)</th><th>New hosts</th><th>New seeds</th></tr></thead>
  <tbody>
    <?php foreach ($runs as $r): ?>
      <tr>
        <td><?= h($r['run_type']) ?></td>
        <td><?= h($r['started_at']) ?></td>
        <td><?= h($r['finished_at'] ?? 'running…') ?></td>
        <td><?= (int)$r['items_added'] ?></td>
        <td><?= (int)$r['links_discovered'] ?></td>
        <td><?= (int)$r['items_removed'] ?></td>
        <td><?= (int)$r['new_hosts_discovered'] ?></td>
        <td><?= (int)$r['new_seeds_discovered'] ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$runs): ?>
      <tr><td colspan="8" class="muted">No harvest runs logged yet.</td></tr>
    <?php endif; ?>
  </tbody>
</table>

<?php require __DIR__ . '/includes/footer.php'; ?>
