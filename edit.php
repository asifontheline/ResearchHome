<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM items WHERE id = ?');
$stmt->execute([$id]);
$item = $stmt->fetch();

if (!$item) {
    http_response_code(404);
    $pageTitle = 'Not found';
    require __DIR__ . '/includes/header.php';
    echo '<p>Item not found.</p>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $url = trim($_POST['url'] ?? '');
    $authors = trim($_POST['authors'] ?? '');
    $abstract = trim($_POST['abstract'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $sourceName = trim($_POST['source_name'] ?? '');
    $publishedDate = trim($_POST['published_date'] ?? '');
    $imageUrl = trim($_POST['image_url'] ?? '');
    $tagsCsv = trim($_POST['tags'] ?? '');

    if ($title === '') $errors[] = 'Title is required.';
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) $errors[] = 'A valid URL is required.';

    if (!$errors) {
        $stmt = db()->prepare(
            'UPDATE items SET title=?, url=?, url_hash=?, authors=?, abstract=?, notes=?, source_name=?, published_date=?, image_url=?
             WHERE id = ?'
        );
        $stmt->execute([
            $title, $url, url_hash($url), $authors ?: null, $abstract ?: null, $notes ?: null,
            $sourceName ?: null, $publishedDate ?: null, $imageUrl ?: null, $id,
        ]);
        set_item_tags($id, resolve_tag_ids($tagsCsv));
        header('Location: /item.php?id=' . $id);
        exit;
    }
    $item['title'] = $title;
    $item['url'] = $url;
    $item['authors'] = $authors;
    $item['abstract'] = $abstract;
    $item['notes'] = $notes;
    $item['source_name'] = $sourceName;
    $item['published_date'] = $publishedDate;
    $item['image_url'] = $imageUrl;
}

$existingTags = array_map(fn($t) => $t['name'], get_item_tags($id));
$tagsCsv = $_POST['tags'] ?? implode(', ', $existingTags);

$pageTitle = 'Edit item';
require __DIR__ . '/includes/header.php';
?>

<h1>Edit item</h1>

<?php foreach ($errors as $e): ?>
  <p class="error"><?= h($e) ?></p>
<?php endforeach; ?>

<form method="post" class="item-form">
  <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">

  <label>URL
    <input type="url" name="url" required value="<?= h($item['url']) ?>">
  </label>

  <label>Title
    <input type="text" name="title" required value="<?= h($item['title']) ?>">
  </label>

  <label>Authors
    <input type="text" name="authors" value="<?= h($item['authors']) ?>">
  </label>

  <label>Source
    <input type="text" name="source_name" value="<?= h($item['source_name']) ?>">
  </label>

  <label>Published date
    <input type="date" name="published_date" value="<?= h($item['published_date']) ?>">
  </label>

  <label>Image URL
    <input type="url" name="image_url" value="<?= h($item['image_url']) ?>">
  </label>

  <label>Abstract
    <textarea name="abstract" rows="6"><?= h($item['abstract']) ?></textarea>
  </label>

  <label>Notes
    <textarea name="notes" rows="4"><?= h($item['notes']) ?></textarea>
  </label>

  <label>Tags <span class="muted">(comma separated)</span>
    <input type="text" name="tags" value="<?= h($tagsCsv) ?>">
  </label>

  <button type="submit">Save changes</button>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
