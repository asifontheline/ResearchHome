<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

$slug = trim($_GET['slug'] ?? $_POST['slug'] ?? '');
// SQL_NO_CACHE -- see get_subjects() in includes/functions.php for why:
// confirmed on production that queries against `subjects` could return a
// stale cached result.
$stmt = db()->prepare('SELECT SQL_NO_CACHE * FROM subjects WHERE slug = ?');
$stmt->execute([$slug]);
$subject = $stmt->fetch();

if (!$subject) {
    http_response_code(404);
    $pageTitle = 'Not found';
    require __DIR__ . '/includes/header.php';
    echo '<p>Subject not found.</p>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $label = trim($_POST['label'] ?? '');
    $parent = trim($_POST['parent'] ?? '');
    $keywordsCsv = trim($_POST['keywords'] ?? '');

    if ($label === '') $errors[] = 'Label is required.';
    if ($parent === '') $errors[] = 'Parent group is required.';
    // 'general' keeps empty keywords on purpose -- see subjects.php --
    // classify_subjects() must never match it via the normal keyword loop,
    // only insert_item_if_new()'s explicit fallback should ever apply it.
    if ($slug === 'general' && trim($keywordsCsv) !== '') {
        $errors[] = '"General" is the zero-tag fallback and must keep no keywords, or it would start matching real content too.';
    }

    if (!$errors) {
        $keywords = $slug === 'general' ? '' : implode(',', array_filter(array_map('trim', explode(',', $keywordsCsv))));
        db()->prepare('UPDATE subjects SET label = ?, parent = ?, keywords = ? WHERE slug = ?')
            ->execute([$label, $parent, $keywords, $slug]);
        header('Location: /subjects_admin.php');
        exit;
    }
    $subject['label'] = $label;
    $subject['parent'] = $parent;
    $subject['keywords'] = $keywordsCsv;
}

$existingParents = array_values(array_unique(array_column(
    db()->query('SELECT SQL_NO_CACHE DISTINCT parent FROM subjects ORDER BY parent ASC')->fetchAll(),
    'parent'
)));

$pageTitle = 'Edit subject';
require __DIR__ . '/includes/header.php';
?>

<h1>Edit subject</h1>

<?php foreach ($errors as $e): ?><p class="error"><?= h($e) ?></p><?php endforeach; ?>

<form method="post" class="item-form">
  <input type="hidden" name="slug" value="<?= h($subject['slug']) ?>">

  <label>Slug <span class="muted">(fixed — this is the tag identity, matched against existing items)</span>
    <input type="text" value="<?= h($subject['slug']) ?>" disabled>
  </label>

  <label>Label
    <input type="text" name="label" required value="<?= h($subject['label']) ?>">
  </label>

  <label>Parent group <span class="muted">(existing groups: <?= h(implode(', ', $existingParents)) ?>)</span>
    <input type="text" name="parent" required list="existing-parents" value="<?= h($subject['parent']) ?>">
    <datalist id="existing-parents">
      <?php foreach ($existingParents as $p): ?><option value="<?= h($p) ?>"><?php endforeach; ?>
    </datalist>
  </label>

  <label>Keywords <span class="muted">(comma separated, specific phrases preferred)</span>
    <input type="text" name="keywords" <?= $subject['slug'] === 'general' ? 'disabled' : '' ?> value="<?= h($subject['keywords']) ?>">
  </label>

  <button type="submit">Save changes</button>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
