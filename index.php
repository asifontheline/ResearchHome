<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$q = trim($_GET['q'] ?? '');
$tagSlug = trim($_GET['tag'] ?? '');

$sql = 'SELECT DISTINCT i.* FROM items i';
$params = [];
$where = [];

if ($tagSlug !== '') {
    $sql .= ' JOIN item_tags it ON it.item_id = i.id JOIN tags t ON t.id = it.tag_id';
    $where[] = 't.slug = ?';
    $params[] = $tagSlug;
}

if ($q !== '') {
    $where[] = 'MATCH(i.title, i.authors, i.abstract, i.notes) AGAINST (? IN NATURAL LANGUAGE MODE)';
    $params[] = $q;
}

if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY i.added_at DESC LIMIT 200';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();

$grouped = get_grouped_subjects();

$pageTitle = 'Browse';
require __DIR__ . '/includes/header.php';
?>

<nav class="subject-bar">
  <a class="all-link <?= $tagSlug === '' ? 'active' : '' ?>" href="/index.php">All items</a>

  <?php foreach ($grouped['groups'] as $parent => $subjects): ?>
    <div class="subject-group">
      <span class="subject-group-label"><?= h($parent) ?></span>
      <div class="subject-pills">
        <?php foreach ($subjects as $s): ?>
          <a class="tag-pill <?= $tagSlug === $s['slug'] ? 'active' : '' ?>" href="/index.php?tag=<?= h($s['slug']) ?>">
            <?= h($s['label']) ?> <span class="count"><?= (int)$s['count'] ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
</nav>

<section class="content">
  <?php if ($q || $tagSlug): ?>
    <p class="filter-summary">
      <?php if ($q): ?>Search: “<?= h($q) ?>”<?php endif; ?>
      <?php if ($tagSlug): ?> Tag: <?= h($tagSlug) ?><?php endif; ?>
      &middot; <a href="/index.php">clear</a>
    </p>
  <?php endif; ?>

  <?php if (!$items): ?>
    <p class="empty-state">Nothing here yet. <?php if (current_user()): ?><a href="/add.php">Add your first item</a>.<?php endif; ?></p>
  <?php endif; ?>

  <div class="item-grid">
    <?php foreach ($items as $item): ?>
      <?php $itemTags = get_item_tags((int)$item['id']); ?>
      <article class="item-card">
        <?php if ($item['image_url']): ?>
          <img class="item-thumb" src="<?= h($item['image_url']) ?>" alt="">
        <?php endif; ?>
        <h3><a href="/item.php?id=<?= (int)$item['id'] ?>"><?= h($item['title']) ?></a></h3>
        <p class="item-meta">
          <?php if ($item['source_name']): ?><span class="source"><?= h($item['source_name']) ?></span><?php endif; ?>
          <?php if ($item['published_date']): ?><span class="date"><?= h($item['published_date']) ?></span><?php endif; ?>
        </p>
        <?php if ($item['authors']): ?><p class="item-authors"><?= h($item['authors']) ?></p><?php endif; ?>
        <?php if ($item['abstract']): ?><p class="item-abstract"><?= h(mb_strimwidth($item['abstract'], 0, 220, '…')) ?></p><?php endif; ?>
        <div class="item-tags">
          <?php foreach ($itemTags as $t): ?>
            <a class="tag-pill" href="/index.php?tag=<?= h($t['slug']) ?>"><?= h($t['name']) ?></a>
          <?php endforeach; ?>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
