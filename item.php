<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$id = (int)($_GET['id'] ?? 0);
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

$itemTags = get_item_tags($id);
$pageTitle = $item['title'];
require __DIR__ . '/includes/header.php';
?>

<article class="item-detail">
  <?php if ($item['image_url']): ?>
    <img class="item-detail-thumb" src="<?= h($item['image_url']) ?>" alt="">
  <?php endif; ?>
  <h1><?= h($item['title']) ?></h1>
  <p class="item-meta">
    <?php if ($item['source_name']): ?><span class="source"><?= h($item['source_name']) ?></span><?php endif; ?>
    <?php if ($item['published_date']): ?><span class="date"><?= h($item['published_date']) ?></span><?php endif; ?>
    <span class="date">added <?= h(substr($item['added_at'], 0, 10)) ?></span>
  </p>
  <?php if ($item['authors']): ?><p class="item-authors"><?= h($item['authors']) ?></p><?php endif; ?>

  <p><a class="external-link" href="<?= h($item['url']) ?>" target="_blank" rel="noopener noreferrer">Open original source ↗</a></p>

  <div class="item-tags">
    <?php foreach ($itemTags as $t): ?>
      <a class="tag-pill" href="/index.php?tag=<?= h($t['slug']) ?>"><?= h($t['name']) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if ($item['abstract']): ?>
    <h2>Abstract</h2>
    <p class="item-abstract-full"><?= nl2br(h($item['abstract'])) ?></p>
  <?php endif; ?>

  <?php if ($item['notes']): ?>
    <h2>Notes</h2>
    <p class="item-notes"><?= nl2br(h($item['notes'])) ?></p>
  <?php endif; ?>

  <?php if (current_user()): ?>
    <p class="item-actions">
      <a href="/edit.php?id=<?= (int)$item['id'] ?>">Edit</a>
      &middot;
      <form method="post" action="/delete.php" class="inline-form" onsubmit="return confirm('Delete this item?');">
        <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
        <button type="submit" class="link-button">Delete</button>
      </form>
    </p>
  <?php endif; ?>
</article>

<?php require __DIR__ . '/includes/footer.php'; ?>
