<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$tags = all_tags_with_counts();
usort($tags, fn($a, $b) => $b['item_count'] <=> $a['item_count']);

$pageTitle = 'All tags';
require __DIR__ . '/includes/header.php';
?>

<h1>All tags</h1>
<p class="muted">
  Every tag in the catalog, most-used first — curated subjects (see
  <a href="/index.php">Browse</a>) plus source-specific classifications
  (arXiv categories, OpenAlex topics, Crossref subject strings, ...) that
  don't get their own pill on the browse page. An item without a tag here
  is still reachable through whatever curated subject it also carries.
</p>

<div class="subject-pills tags-directory">
  <?php foreach ($tags as $t): ?>
    <a class="tag-pill" href="/index.php?tag=<?= h($t['slug']) ?>">
      <?= h($t['name']) ?> <span class="count"><?= (int)$t['item_count'] ?></span>
    </a>
  <?php endforeach; ?>
  <?php if (!$tags): ?>
    <p class="muted">No tags yet.</p>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
