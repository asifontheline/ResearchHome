<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

$runs = db()->query('SELECT * FROM harvest_log ORDER BY started_at DESC LIMIT 50')->fetchAll();
$activity = get_harvest_activity_by_source(30);
$traffic = get_traffic_summary();
$searchMisses = db()->query(
    "SELECT query, search_count, first_searched_at, harvested_at, items_found
     FROM search_misses ORDER BY harvested_at IS NULL DESC, search_count DESC, first_searched_at DESC LIMIT 20"
)->fetchAll();
$mapPointsAll = get_traffic_map_points('all');

$pageTitle = 'Harvest log';
require __DIR__ . '/includes/header.php';
?>

<h1>Harvest log</h1>
<p class="muted">
  Two independent jobs log here: <a href="/harvest.php">harvest.php</a> (content —
  API sources, crawl, link-health) and <a href="/discover.php">discover.php</a>
  (source discovery only — proposes new seeds, doesn't touch content).
  <a href="/seeds.php">Manage seed URLs</a>.
</p>

<h2>Traffic</h2>
<p class="muted">
  MVP page-view log (public visitors only, best-effort bot filtering, no raw IPs
  stored — the IP is only used transiently to resolve a city/region/country via
  ip-api.com, then discarded). Location tracking added <?= date('Y-m-d') ?>, trying
  it for a week before deciding whether to keep it.
</p>
<table class="seed-table traffic-summary-table">
  <thead><tr><th></th><th>Views</th><th>Unique visitors</th></tr></thead>
  <tbody>
    <tr><td>Today</td><td><?= number_format($traffic['today']['views']) ?></td><td><?= number_format($traffic['today']['unique_visitors']) ?></td></tr>
    <tr><td>Last 7 days</td><td><?= number_format($traffic['last_7_days']['views']) ?></td><td><?= number_format($traffic['last_7_days']['unique_visitors']) ?></td></tr>
    <tr><td>Last 30 days</td><td><?= number_format($traffic['last_30_days']['views']) ?></td><td><?= number_format($traffic['last_30_days']['unique_visitors']) ?></td></tr>
  </tbody>
</table>

<h3>All-time visitor map</h3>
<?= render_world_map($mapPointsAll, 'world-map-all') ?>

<div class="traffic-columns">
  <div>
    <h3>Top pages (7d)</h3>
    <?php if ($traffic['top_pages']): ?>
      <ol class="credits-list source-credits-list">
        <?php foreach ($traffic['top_pages'] as $p): ?>
          <li><?= h($p['path']) ?> (<?= (int)$p['views'] ?>)</li>
        <?php endforeach; ?>
      </ol>
    <?php else: ?>
      <p class="muted">No data yet.</p>
    <?php endif; ?>
  </div>
  <div>
    <h3>Top items (7d)</h3>
    <?php if ($traffic['top_items']): ?>
      <ol class="credits-list source-credits-list">
        <?php foreach ($traffic['top_items'] as $i): ?>
          <li><a href="/item.php?id=<?= (int)$i['item_id'] ?>"><?= h($i['title']) ?></a> (<?= (int)$i['views'] ?>)</li>
        <?php endforeach; ?>
      </ol>
    <?php else: ?>
      <p class="muted">No data yet.</p>
    <?php endif; ?>
  </div>
  <div>
    <h3>Top referrers (7d)</h3>
    <?php if ($traffic['top_referrers']): ?>
      <ol class="credits-list source-credits-list">
        <?php foreach ($traffic['top_referrers'] as $r): ?>
          <li><?= h($r['referrer_host']) ?> (<?= (int)$r['views'] ?>)</li>
        <?php endforeach; ?>
      </ol>
    <?php else: ?>
      <p class="muted">None yet, or all direct/internal traffic.</p>
    <?php endif; ?>
  </div>
  <div>
    <h3>Top locations (7d)</h3>
    <p class="muted" style="font-size:0.8rem;">City/region from IP geolocation, no IP retained. Trying this for a week.</p>
    <?php if ($traffic['top_locations']): ?>
      <ol class="credits-list source-credits-list">
        <?php foreach ($traffic['top_locations'] as $loc): ?>
          <li>
            <?= h(array_filter([$loc['city'], $loc['region'], $loc['country']]) ? implode(', ', array_filter([$loc['city'], $loc['region'], $loc['country']])) : 'Unknown') ?>
            (<?= (int)$loc['views'] ?> views, <?= (int)$loc['unique_visitors'] ?> visitors)
          </li>
        <?php endforeach; ?>
      </ol>
    <?php else: ?>
      <p class="muted">No data yet.</p>
    <?php endif; ?>
  </div>
</div>

<hr class="section-divider">

<h2>Zero-result searches</h2>
<p class="muted">
  Public searches with no matches get queued here; the harvester tries each one
  (highest search_count first, up to 3 per run) as a direct keyword search across
  the API sources. "Harvested" means attempted, not necessarily successful —
  items_found may still be 0 if nothing free/open exists for that query.
</p>
<?php if ($searchMisses): ?>
  <table class="seed-table">
    <thead><tr><th>Query</th><th>Times searched</th><th>First searched</th><th>Status</th><th>Items found</th></tr></thead>
    <tbody>
      <?php foreach ($searchMisses as $sm): ?>
        <tr>
          <td><?= h($sm['query']) ?></td>
          <td><?= (int)$sm['search_count'] ?></td>
          <td><?= h($sm['first_searched_at']) ?></td>
          <td><?= $sm['harvested_at'] ? 'harvested ' . h($sm['harvested_at']) : 'pending' ?></td>
          <td><?= $sm['items_found'] === null ? '—' : (int)$sm['items_found'] ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php else: ?>
  <p class="muted">None yet.</p>
<?php endif; ?>

<hr class="section-divider">

<h2>Items added, last 30 days</h2>
<?= render_activity_chart($activity) ?>

<h2>Runs</h2>
<button type="button" id="run-harvest-btn">Run harvest now</button>
<button type="button" id="run-discover-btn">Run discovery now</button>
<p id="run-now-status" class="muted"></p>

<table class="seed-table">
  <thead><tr><th>Type</th><th>Started (UTC)</th><th>Finished (UTC)</th><th>Items added</th><th>Links discovered</th><th>Links checked</th><th>Items removed (dead links)</th><th>New hosts</th><th>New seeds</th><th>Errors</th></tr></thead>
  <tbody>
    <?php foreach ($runs as $r): ?>
      <tr>
        <td><?= h($r['run_type']) ?></td>
        <td><?= h($r['started_at']) ?></td>
        <td><?= h($r['finished_at'] ?? 'running…') ?></td>
        <td><?= (int)$r['items_added'] ?></td>
        <td><?= (int)$r['links_discovered'] ?></td>
        <td><?= (int)$r['links_checked'] ?></td>
        <td><?= (int)$r['items_removed'] ?></td>
        <td><?= (int)$r['new_hosts_discovered'] ?></td>
        <td><?= (int)$r['new_seeds_discovered'] ?></td>
        <td><?= (int)$r['errors'] ?><?php if ($r['detail']): ?> <details><summary>detail</summary><pre><?= h($r['detail']) ?></pre></details><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$runs): ?>
      <tr><td colspan="10" class="muted">No runs yet. Set up cron, or click one of the buttons above.</td></tr>
    <?php endif; ?>
  </tbody>
</table>

<script>
function runNow(url, btn) {
  var status = document.getElementById('run-now-status');
  status.textContent = 'Running… this can take up to a minute.';
  btn.disabled = true;
  fetch(url, { method: 'POST' })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (data.error) {
        status.textContent = 'Error: ' + data.error;
        return;
      }
      status.textContent = 'Done.';
      setTimeout(function () { location.reload(); }, 1200);
    })
    .catch(function () { status.textContent = 'Request failed.'; })
    .finally(function () { btn.disabled = false; });
}
document.getElementById('run-harvest-btn').addEventListener('click', function () { runNow('/harvest.php', this); });
document.getElementById('run-discover-btn').addEventListener('click', function () { runNow('/discover.php', this); });
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
