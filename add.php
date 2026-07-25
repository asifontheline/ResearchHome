<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

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
        $tagNames = array_filter(array_map('trim', explode(',', $tagsCsv)));
        $itemId = insert_item_if_new([
            'title' => $title, 'url' => $url, 'authors' => $authors ?: null,
            'abstract' => $abstract ?: null, 'notes' => $notes ?: null,
            'source_name' => $sourceName ?: null, 'published_date' => $publishedDate ?: null,
            'image_url' => $imageUrl ?: null,
        ], $tagNames);
        if ($itemId === null) {
            $errors[] = 'An item with this URL already exists.';
        } else {
            header('Location: /item.php?id=' . $itemId);
            exit;
        }
    }
}

$pageTitle = 'Add item';
require __DIR__ . '/includes/header.php';
?>

<h1>Add a research item</h1>

<?php foreach ($errors as $e): ?>
  <p class="error"><?= h($e) ?></p>
<?php endforeach; ?>

<form method="post" class="item-form">
  <label>URL
    <div class="fetch-row">
      <input type="url" name="url" id="url" required value="<?= h($_POST['url'] ?? '') ?>" placeholder="https://arxiv.org/abs/2401.00001, https://doi.org/10.1000/xyz123, https://patents.google.com/patent/..., or any link">
      <button type="button" id="fetch-btn">Fetch metadata</button>
    </div>
  </label>
  <p id="fetch-status" class="muted"></p>

  <label>Title
    <input type="text" name="title" id="title" required value="<?= h($_POST['title'] ?? '') ?>">
  </label>

  <label>Authors
    <input type="text" name="authors" id="authors" value="<?= h($_POST['authors'] ?? '') ?>">
  </label>

  <label>Source
    <input type="text" name="source_name" id="source_name" value="<?= h($_POST['source_name'] ?? '') ?>" placeholder="arXiv, PubMed, Google Patents, publisher name…">
  </label>

  <label>Published date
    <input type="date" name="published_date" id="published_date" value="<?= h($_POST['published_date'] ?? '') ?>">
  </label>

  <label>Image URL
    <input type="url" name="image_url" id="image_url" value="<?= h($_POST['image_url'] ?? '') ?>">
  </label>

  <label>Abstract
    <textarea name="abstract" id="abstract" rows="6"><?= h($_POST['abstract'] ?? '') ?></textarea>
  </label>

  <label>Notes
    <textarea name="notes" rows="4"><?= h($_POST['notes'] ?? '') ?></textarea>
  </label>

  <label>Tags <span class="muted">(comma separated — type and topic both go here, e.g. "paper, patent, ai, biology")</span>
    <input type="text" name="tags" value="<?= h($_POST['tags'] ?? '') ?>">
  </label>

  <button type="submit">Save item</button>
</form>

<script src="/assets/app.js"></script>

<?php require __DIR__ . '/includes/footer.php'; ?>
