<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $label = trim($_POST['label'] ?? '');
        $parent = trim($_POST['parent'] ?? '');
        $keywordsCsv = trim($_POST['keywords'] ?? '');

        if ($label === '') $errors[] = 'Label is required.';
        if ($parent === '') $errors[] = 'Parent group is required.';

        if (!$errors) {
            $slug = slugify($label);
            $existing = db()->prepare('SELECT id FROM subjects WHERE slug = ?');
            $existing->execute([$slug]);
            if ($existing->fetch()) {
                $errors[] = "A subject with slug \"{$slug}\" already exists — edit it instead of adding a duplicate.";
            } else {
                $keywords = implode(',', array_filter(array_map('trim', explode(',', $keywordsCsv))));
                db()->prepare('INSERT INTO subjects (slug, label, parent, keywords) VALUES (?, ?, ?, ?)')
                    ->execute([$slug, $label, $parent, $keywords]);
            }
        }
    } elseif ($action === 'delete') {
        $slug = trim($_POST['slug'] ?? '');
        // Only removes it from the curated taxonomy (browse sidebar +
        // classify_subjects() matching going forward) -- doesn't touch the
        // `tags`/`item_tags` tables, so items already carrying this tag
        // keep it (still visible via tags.php's full tag list).
        if ($slug !== '' && $slug !== 'general') {
            db()->prepare('DELETE FROM subjects WHERE slug = ?')->execute([$slug]);
        }
    }
}

$subjects = db()->query('SELECT slug, label, parent, keywords FROM subjects ORDER BY parent ASC, label ASC')->fetchAll();
$existingParents = array_values(array_unique(array_column($subjects, 'parent')));
sort($existingParents);

$pageTitle = 'Subjects';
require __DIR__ . '/includes/header.php';
?>

<p style="background:#ff0;color:#000;padding:1rem;font-family:monospace;">
  DIAGNOSTIC (remove after use) --
  DB_NAME=<?= h(DB_NAME) ?> |
  fresh-connection COUNT(*)=<?= (int) db(true)->query('SELECT COUNT(*) FROM subjects')->fetchColumn() ?> |
  server time=<?= h(date('Y-m-d H:i:s')) ?> UTC |
  file mtime=<?= h(date('Y-m-d H:i:s', filemtime(__FILE__))) ?> UTC
</p>

<h1>Subjects</h1>
<p class="muted">
  The curated taxonomy driving the browse sidebar and <code>classify_subjects()</code>
  keyword matching (see <code>includes/functions.php</code>). Stored in the
  database, not a file, so additions here survive the next deploy.
  Keywords are comma-separated and matched on word boundaries — avoid bare
  common words (e.g. "law", "design") since they show up constantly in
  unrelated writing; prefer specific compound phrases instead.
</p>

<?php foreach ($errors as $e): ?><p class="error"><?= h($e) ?></p><?php endforeach; ?>

<h2>Add a subject</h2>
<form method="post" class="item-form">
  <input type="hidden" name="action" value="add">
  <label>Label
    <input type="text" name="label" required list="existing-labels" placeholder="e.g. Veterinary Science">
    <datalist id="existing-labels">
      <?php foreach ($subjects as $s): ?><option value="<?= h($s['label']) ?>"><?php endforeach; ?>
    </datalist>
  </label>
  <label>Parent group <span class="muted">(existing groups: <?= h(implode(', ', $existingParents)) ?>)</span>
    <input type="text" name="parent" required list="existing-parents" placeholder="e.g. Life Sciences & Medicine">
    <datalist id="existing-parents">
      <?php foreach ($existingParents as $p): ?><option value="<?= h($p) ?>"><?php endforeach; ?>
    </datalist>
  </label>
  <label>Keywords <span class="muted">(comma separated, specific phrases preferred)</span>
    <input type="text" name="keywords" placeholder="e.g. veterinary medicine, veterinary science, animal health">
  </label>
  <button type="submit">Add subject</button>
</form>

<h2>All subjects (<?= count($subjects) ?>)</h2>
<table class="seed-table">
  <thead><tr><th>Label</th><th>Parent</th><th>Keywords</th><th></th></tr></thead>
  <tbody>
    <?php foreach ($subjects as $s): ?>
      <tr>
        <td><?= h($s['label']) ?></td>
        <td><?= h($s['parent']) ?></td>
        <td class="muted"><?= h($s['keywords']) ?: '—' ?></td>
        <td>
          <a href="/subject_edit.php?slug=<?= urlencode($s['slug']) ?>">edit</a>
          <?php if ($s['slug'] !== 'general'): ?>
            <form method="post" class="inline-form" onsubmit="return confirm('Delete this subject from the taxonomy? Items already tagged with it keep the tag.');">
              <input type="hidden" name="slug" value="<?= h($s['slug']) ?>">
              <input type="hidden" name="action" value="delete">
              <button type="submit" class="link-button">delete</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<?php require __DIR__ . '/includes/footer.php'; ?>
