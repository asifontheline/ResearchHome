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
    $addAsSeed = isset($_POST['add_as_seed']);
    $seedSubjectSlug = trim($_POST['seed_subject_slug'] ?? '') ?: null;

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
            if ($addAsSeed) {
                $host = parse_url($url, PHP_URL_HOST);
                if ($host) {
                    db()->prepare(
                        'INSERT IGNORE INTO seed_urls (url, host, subject_slug, active) VALUES (?, ?, ?, 1)'
                    )->execute([$url, $host, $seedSubjectSlug]);
                }
            }
            header('Location: /item.php?id=' . $itemId);
            exit;
        }
    }
}

$subjects = get_subjects();
$existingTagNames = all_tag_names();

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

  <label>Tags <span class="muted">(comma separated — type and topic both go here, e.g. "paper, patent, ai, biology"; suggestions are existing tags, to avoid near-duplicates)</span>
    <input type="text" name="tags" list="existing-tags" value="<?= h($_POST['tags'] ?? '') ?>">
    <datalist id="existing-tags">
      <?php foreach ($existingTagNames as $t): ?><option value="<?= h($t) ?>"><?php endforeach; ?>
    </datalist>
  </label>

  <label class="checkbox-label">
    <input type="checkbox" name="add_as_seed" value="1" id="add_as_seed" <?= isset($_POST['add_as_seed']) ? 'checked' : '' ?>>
    Also add this URL as a crawl seed — the harvester will explore outbound links from it on future runs
  </label>

  <label id="seed_subject_row" style="display: none;">Seed subject <span class="muted">(optional — for classifying items the crawler discovers here)</span>
    <select name="seed_subject_slug">
      <option value="">— none —</option>
      <?php foreach ($subjects as $slug => $def): ?>
        <option value="<?= h($slug) ?>" <?= ($_POST['seed_subject_slug'] ?? '') === $slug ? 'selected' : '' ?>><?= h($def['label']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>

  <button type="submit">Save item</button>
</form>

<script>
(function () {
  var checkbox = document.getElementById('add_as_seed');
  var row = document.getElementById('seed_subject_row');
  function sync() { row.style.display = checkbox.checked ? '' : 'none'; }
  checkbox.addEventListener('change', sync);
  sync();
})();
</script>
<script src="/assets/app.js"></script>

<?php require __DIR__ . '/includes/footer.php'; ?>
