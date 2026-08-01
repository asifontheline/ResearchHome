<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$q = trim($_GET['q'] ?? '');
$perPage = 24;

$where = ["content_type = 'video'"];
$params = [];
if ($q !== '') {
    $where[] = 'MATCH(title, authors, abstract, notes) AGAINST (? IN NATURAL LANGUAGE MODE)';
    $params[] = $q;
}
$whereClause = ' WHERE ' . implode(' AND ', $where);

$countStmt = db()->prepare("SELECT COUNT(*) FROM items{$whereClause}");
$countStmt->execute($params);
$totalItems = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalItems / $perPage));

$page = max(1, min($totalPages, (int) ($_GET['page'] ?? 1)));
$offset = ($page - 1) * $perPage;

$stmt = db()->prepare("SELECT * FROM items{$whereClause} ORDER BY added_at DESC LIMIT {$perPage} OFFSET {$offset}");
$stmt->execute($params);
$videos = $stmt->fetchAll();

/** Builds a pagination link preserving the current search. */
function video_paginate_url(int $page, string $q): string {
    $params = ['page' => $page];
    if ($q !== '') $params['q'] = $q;
    return '/videos.php?' . http_build_query($params);
}

$pageTitle = 'Videos';
require __DIR__ . '/includes/header.php';
?>

<h1>Video research channels</h1>
<p class="muted" style="max-width: 70ch;">
  A separate section from the main research catalog — talks, lectures, and
  explainers from YouTube and Vimeo, harvested the same way (see
  <a href="/about.php">About</a>), searched and tagged like everything else.
  Same "nothing is copied" principle: every entry is a link back to the
  original video, never a copy.
</p>

<form class="search-form video-search-form" action="/videos.php" method="get">
  <input type="text" name="q" placeholder="Search video titles, descriptions…" value="<?= h($q) ?>">
  <button type="submit">Search</button>
  <?php if ($q !== ''): ?><a href="/videos.php">clear</a><?php endif; ?>
</form>

<?php if ($totalItems > 0): ?>
  <p class="result-count">
    <?= number_format($totalItems) ?> video<?= $totalItems === 1 ? '' : 's' ?>
    <?php if ($totalPages > 1): ?> — page <?= $page ?> of <?= number_format($totalPages) ?><?php endif; ?>
  </p>
<?php endif; ?>

<?php if (!$videos): ?>
  <p class="empty-state">
    <?= $q !== '' ? 'No matches for “' . h($q) . '”.' : 'No videos harvested yet.' ?>
  </p>
<?php endif; ?>

<div class="item-grid">
  <?php foreach ($videos as $v): ?>
    <?php $vTags = get_item_tags((int)$v['id']); ?>
    <article class="item-card">
      <?php if ($v['image_url']): ?>
        <img class="item-thumb" src="<?= h($v['image_url']) ?>" alt="">
      <?php endif; ?>
      <h3><a href="/item.php?id=<?= (int)$v['id'] ?>"><?= h($v['title']) ?></a></h3>
      <p class="item-meta">
        <?php if ($v['source_name']): ?><span class="source"><?= h($v['source_name']) ?></span><?php endif; ?>
        <?php if ($v['published_date']): ?><span class="date"><?= h($v['published_date']) ?></span><?php endif; ?>
      </p>
      <?php if ($v['abstract']): ?><p class="item-abstract"><?= h(mb_strimwidth($v['abstract'], 0, 220, '…')) ?></p><?php endif; ?>
      <div class="item-tags">
        <?php foreach ($vTags as $t): ?>
          <a class="tag-pill" href="/index.php?tag=<?= h($t['slug']) ?>"><?= h($t['name']) ?></a>
        <?php endforeach; ?>
      </div>
    </article>
  <?php endforeach; ?>
</div>

<?php if ($totalPages > 1): ?>
  <nav class="pagination" aria-label="Pagination">
    <?php if ($page > 1): ?>
      <a href="<?= h(video_paginate_url($page - 1, $q)) ?>">&laquo; Prev</a>
    <?php else: ?>
      <span class="pagination-disabled">&laquo; Prev</span>
    <?php endif; ?>
    <span class="pagination-current"><?= $page ?></span> of <?= number_format($totalPages) ?>
    <?php if ($page < $totalPages): ?>
      <a href="<?= h(video_paginate_url($page + 1, $q)) ?>">Next &raquo;</a>
    <?php else: ?>
      <span class="pagination-disabled">Next &raquo;</span>
    <?php endif; ?>
  </nav>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
