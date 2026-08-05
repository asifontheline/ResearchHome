<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

// Enable/disable is the one action available right on this public page,
// and only when logged in -- everything else (add/approve/delete) stays
// on seeds.php. Guarded the same way seeds.php guards its own POSTs.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_seed') {
    require_login();
    $id = (int)($_POST['id'] ?? 0);
    $current = db()->prepare('SELECT active FROM seed_urls WHERE id = ?');
    $current->execute([$id]);
    $wasActive = (bool) $current->fetchColumn();
    // Same reset-on-re-enable behavior as seeds.php's toggle action -- a
    // manual re-enable is an explicit "give it another chance" that should
    // override the automatic permanent-disable/cooldown state.
    if ($wasActive) {
        db()->prepare('UPDATE seed_urls SET active = 0 WHERE id = ?')->execute([$id]);
    } else {
        db()->prepare('UPDATE seed_urls SET active = 1, failed_fetches = 0, block_cycles = 0, permanently_disabled = 0 WHERE id = ?')->execute([$id]);
    }
    header('Location: /activity.php#seeds');
    exit;
}

$activity = get_harvest_activity_by_source(30);
$runs = db()->query('SELECT * FROM harvest_log ORDER BY started_at DESC LIMIT 20')->fetchAll();
// Same set seeds.php's admin table shows as "active", minus anything still
// sitting in the discovery review queue (discovered=1, active=0) -- that's
// an internal moderation step, not vetted yet, so it stays admin-only.
// Visible to everyone; only the enable/disable toggle (handled above)
// requires login -- add/approve/delete stay on seeds.php.
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

<h2 id="seeds">Active &amp; disabled seeds</h2>
<p class="muted">
  Hub / listing pages the crawler starts from (e.g. an arXiv category listing,
  a topic RSS feed, a search results page) — it follows outbound links one hop
  and only touches pages <code>robots.txt</code> allows, rate-limited per host.
  A seed disables itself automatically after repeated failed fetches.
</p>
<table class="seed-table">
  <thead><tr><th>URL</th><th>Subject</th><th>Active</th><th>Last crawled</th><?php if (current_user()): ?><th></th><?php endif; ?></tr></thead>
  <tbody>
    <?php foreach ($publicSeeds as $s): ?>
      <tr>
        <td><a href="<?= h($s['url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($s['url']) ?></a></td>
        <td><?= h(subject_label($s['subject_slug'])) ?></td>
        <td><?= $s['active'] ? 'yes' : 'no' ?></td>
        <td><?= h($s['last_crawled_at'] ?? 'never') ?></td>
        <?php if (current_user()): ?>
          <td>
            <form method="post" class="inline-form">
              <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
              <input type="hidden" name="action" value="toggle_seed">
              <button type="submit" class="link-button"><?= $s['active'] ? 'disable' : 'enable' ?></button>
            </form>
          </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    <?php if (!$publicSeeds): ?>
      <tr><td colspan="<?= current_user() ? 5 : 4 ?>" class="muted">No seeds yet.</td></tr>
    <?php endif; ?>
  </tbody>
</table>

<?php require __DIR__ . '/includes/footer.php'; ?>
