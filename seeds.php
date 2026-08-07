<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/harvester.php'; // assign_next_seed_group()
require_login();
ensure_seed_urls_first_failed_at_column();

$subjects = get_subjects();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $url = trim($_POST['url'] ?? '');
        $subjectSlug = trim($_POST['subject_slug'] ?? '') ?: null;
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $errors[] = 'Enter a valid hub/listing page URL.';
        } else {
            $host = parse_url($url, PHP_URL_HOST);
            $stmt = db()->prepare('INSERT IGNORE INTO seed_urls (url, host, subject_slug) VALUES (?, ?, ?)');
            $stmt->execute([$url, $host, $subjectSlug]);
            // Admin-added seeds start active=1 immediately (unlike a
            // discovered one awaiting review), so it needs a crawl-slot
            // group right away too.
            if ($stmt->rowCount() > 0) {
                assign_next_seed_group((int) db()->lastInsertId());
            }
        }
    } elseif ($action === 'approve') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare('UPDATE seed_urls SET active = 1, discovered = 0 WHERE id = ?')->execute([$id]);
        assign_next_seed_group($id);
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $current = db()->prepare('SELECT active FROM seed_urls WHERE id = ?');
        $current->execute([$id]);
        $wasActive = (bool) $current->fetchColumn();
        // Reset the failure counter on re-enable so a manual retry gets a
        // fresh run at SEED_FAILURE_THRESHOLD rather than disabling itself
        // again on the very next failure. Also clears block_cycles/
        // permanently_disabled — a manual re-enable is an explicit "give it
        // another chance" that should override the automatic permanent-disable,
        // same as it already overrides the automatic 24h-cooldown wait.
        if ($wasActive) {
            db()->prepare('UPDATE seed_urls SET active = 0 WHERE id = ?')->execute([$id]);
        } else {
            db()->prepare('UPDATE seed_urls SET active = 1, failed_fetches = 0, first_failed_at = NULL, block_cycles = 0, permanently_disabled = 0 WHERE id = ?')->execute([$id]);
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare('DELETE FROM seed_urls WHERE id = ?')->execute([$id]);
    }
}

$pending = db()->query("SELECT * FROM seed_urls WHERE discovered = 1 AND active = 0 ORDER BY added_at DESC")->fetchAll();
$active = db()->query("SELECT * FROM seed_urls WHERE NOT (discovered = 1 AND active = 0) ORDER BY added_at DESC")->fetchAll();

$pageTitle = 'Seed URLs';
require __DIR__ . '/includes/header.php';
?>

<h1>Seed URLs</h1>
<p class="muted">
  Hub / listing pages the crawler starts from (e.g. an arXiv category listing,
  a topic RSS feed, a search results page). It follows outbound links one hop
  and only touches pages <code>robots.txt</code> allows, rate-limited per host.
</p>

<?php foreach ($errors as $e): ?><p class="error"><?= h($e) ?></p><?php endforeach; ?>

<?php if ($pending): ?>
  <h2>Pending review (<?= count($pending) ?>)</h2>
  <p class="muted">
    Proposed automatically by the source-discovery crawler — not crawled until you approve.
    See <a href="/credits.php">Credits</a> for how discovery works.
  </p>
  <table class="seed-table">
    <thead><tr><th>URL</th><th>Discovered via</th><th>Proposed</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($pending as $s): ?>
        <tr>
          <td><a href="<?= h($s['url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($s['url']) ?></a></td>
          <td><?= h($s['discovery_source']) ?></td>
          <td><?= h(substr($s['added_at'], 0, 10)) ?></td>
          <td>
            <form method="post" class="inline-form">
              <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
              <input type="hidden" name="action" value="approve">
              <button type="submit" class="link-button">approve</button>
            </form>
            <form method="post" class="inline-form" onsubmit="return confirm('Reject this proposed seed?');">
              <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
              <input type="hidden" name="action" value="delete">
              <button type="submit" class="link-button">reject</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<h2>Add a seed manually</h2>
<form method="post" class="item-form">
  <input type="hidden" name="action" value="add">
  <label>Hub / listing page URL
    <input type="url" name="url" required placeholder="https://arxiv.org/list/cs.AI/recent">
  </label>
  <label>Subject <span class="muted">(optional — leave blank for a general-purpose source)</span>
    <select name="subject_slug">
      <option value="">— none —</option>
      <?php foreach ($subjects as $slug => $def): ?>
        <option value="<?= h($slug) ?>"><?= h($def['label']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <button type="submit">Add seed</button>
</form>

<h2>Active &amp; disabled seeds</h2>
<table class="seed-table">
  <thead><tr><th>URL</th><th>Subject</th><th>Active</th><th>Last crawled</th><th>Successful fetches</th><th>Failed fetches</th><th>Block cycles</th><th></th></tr></thead>
  <tbody>
    <?php foreach ($active as $s): ?>
      <tr>
        <td><a href="<?= h($s['url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($s['url']) ?></a></td>
        <td><?= h(subject_label($s['subject_slug'])) ?></td>
        <td><?= $s['active'] ? 'yes' : 'no' ?></td>
        <td><?= h($s['last_crawled_at'] ?? 'never') ?></td>
        <td><?= (int)$s['successful_fetches'] ?></td>
        <td>
          <?= (int)$s['failed_fetches'] ?><?= (int)$s['failed_fetches'] >= 3 && !$s['active'] ? ' — auto-disabled' : '' ?>
          <?php if ($s['first_failed_at'] && $s['active']): ?>
            <span class="muted">(failing <?= round((time() - strtotime($s['first_failed_at'])) / 86400, 1) ?>/<?= SEED_FAILURE_MIN_DAYS ?> days)</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($s['permanently_disabled']): ?>
            <strong><?= (int)$s['block_cycles'] ?>/7 — permanently disabled</strong>
          <?php elseif ((int)$s['block_cycles'] > 0): ?>
            <?= (int)$s['block_cycles'] ?>/7
          <?php else: ?>
            —
          <?php endif; ?>
        </td>
        <td>
          <form method="post" class="inline-form">
            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <input type="hidden" name="action" value="toggle">
            <button type="submit" class="link-button"><?= $s['active'] ? 'disable' : 'enable' ?></button>
          </form>
          <form method="post" class="inline-form" onsubmit="return confirm('Delete this seed?');">
            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <input type="hidden" name="action" value="delete">
            <button type="submit" class="link-button">delete</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$active): ?>
      <tr><td colspan="8" class="muted">No seeds yet.</td></tr>
    <?php endif; ?>
  </tbody>
</table>

<?php require __DIR__ . '/includes/footer.php'; ?>
