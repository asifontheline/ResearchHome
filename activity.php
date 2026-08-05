<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$activity = get_harvest_activity_by_source(30);
$runs = db()->query('SELECT * FROM harvest_log ORDER BY started_at DESC LIMIT 20')->fetchAll();
// Same set seeds.php's admin table shows as "active", minus anything still
// sitting in the discovery review queue (discovered=1, active=0) -- that's
// an internal moderation step, not vetted yet, so it stays admin-only.
// Public, read-only: no toggle/delete actions here, see seeds.php for those.
$publicSeeds = db()->query(
    "SELECT * FROM seed_urls WHERE NOT (discovered = 1 AND active = 0) ORDER BY added_at DESC"
)->fetchAll();

$pageTitle = 'Activity';
require __DIR__ . '/includes/header.php';
?>

<p class="project-description">
  ResHub (Research Hub) automatically discovers and catalogs freely available research — papers,
  patents, and articles — from arXiv, Crossref, PubMed, OpenAlex, and more. Harvested every 15
  minutes, with a discovery phase every half hour looking for new sources.
  Nothing here is copied; every item is metadata plus a link back to its original source. This is a
  dynamic website that discovers newly available information and adds it continuously — keep checking
  in and discover more!
</p>

<h1>Items added, last 30 days</h1>
<?= render_activity_chart($activity) ?>

<h2>Recent harvest runs</h2>
<table class="seed-table">
  <thead><tr><th>Type</th><th>Started (UTC)</th><th>Finished (UTC)</th><th>Items added</th><th>Links discovered</th><th>Items removed (dead links)</th><th>New hosts</th><th>New seeds</th></tr></thead>
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

<h2>Active &amp; disabled seeds</h2>
<p class="muted">
  Hub / listing pages the crawler starts from (e.g. an arXiv category listing,
  a topic RSS feed, a search results page) — it follows outbound links one hop
  and only touches pages <code>robots.txt</code> allows, rate-limited per host.
  A seed disables itself automatically after repeated failed fetches.
</p>
<table class="seed-table">
  <thead><tr><th>URL</th><th>Subject</th><th>Active</th><th>Last crawled</th></tr></thead>
  <tbody>
    <?php foreach ($publicSeeds as $s): ?>
      <tr>
        <td><a href="<?= h($s['url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($s['url']) ?></a></td>
        <td><?= h(subject_label($s['subject_slug'])) ?></td>
        <td><?= $s['active'] ? 'yes' : 'no' ?></td>
        <td><?= h($s['last_crawled_at'] ?? 'never') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$publicSeeds): ?>
      <tr><td colspan="4" class="muted">No seeds yet.</td></tr>
    <?php endif; ?>
  </tbody>
</table>

<?php require __DIR__ . '/includes/footer.php'; ?>
