<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$q = trim($_GET['q'] ?? '');
$tagSlug = trim($_GET['tag'] ?? '');
$perPage = 24;

$joinClause = '';
$params = [];
$where = [];

if ($tagSlug !== '') {
    $joinClause = ' JOIN item_tags it ON it.item_id = i.id JOIN tags t ON t.id = it.tag_id';
    $where[] = 't.slug = ?';
    $params[] = $tagSlug;
}

if ($q !== '') {
    $where[] = 'MATCH(i.title, i.authors, i.abstract, i.notes) AGAINST (? IN NATURAL LANGUAGE MODE)';
    $params[] = $q;
}

$whereClause = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$countStmt = db()->prepare("SELECT COUNT(DISTINCT i.id) FROM items i{$joinClause}{$whereClause}");
$countStmt->execute($params);
$totalItems = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalItems / $perPage));

$page = max(1, min($totalPages, (int) ($_GET['page'] ?? 1)));
$offset = ($page - 1) * $perPage;

$sql = "SELECT DISTINCT i.* FROM items i{$joinClause}{$whereClause}
        ORDER BY i.added_at DESC LIMIT {$perPage} OFFSET {$offset}";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();

$grouped = get_grouped_subjects();

/** Builds a pagination link preserving the current q/tag filters. */
function paginate_url(int $page, string $q, string $tagSlug): string {
    $params = ['page' => $page];
    if ($q !== '') $params['q'] = $q;
    if ($tagSlug !== '') $params['tag'] = $tagSlug;
    return '/index.php?' . http_build_query($params);
}

$pageTitle = 'Browse';
require __DIR__ . '/includes/header.php';

$tickerText = 'ResHub (Research Hub) automatically discovers and catalogs freely available research — papers, patents, and articles — from arXiv, Crossref, PubMed, OpenAlex, and more. Hourly harvest, with a discovery phase every half hour looking for new sources. This is a dynamic website that discovers newly available information and adds it every hour — keep checking in and discover more!';
?>

<div class="ticker" role="note" aria-label="About ResHub">
  <div class="ticker-track">
    <span><?= h($tickerText) ?></span>
    <span aria-hidden="true"><?= h($tickerText) ?></span>
  </div>
</div>

<nav class="subject-bar">
  <div class="top-links">
    <a class="all-link <?= $tagSlug === '' ? 'active' : '' ?>" href="/index.php">By Subject</a>
    <span class="top-links-sep">&middot;</span>
    <a class="all-link" href="/tags.php">By Tag</a>
  </div>

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

  <?php if ($grouped['overflow_count'] > 0): ?>
    <a class="tags-overflow-link" href="/tags.php">+<?= $grouped['overflow_count'] ?> more specialized topics &rarr;</a>
  <?php endif; ?>
</nav>

<section class="content">
  <?php if ($q || $tagSlug): ?>
    <p class="filter-summary">
      <?php if ($q): ?>Search: “<?= h($q) ?>”<?php endif; ?>
      <?php if ($tagSlug): ?> Tag: <?= h($tagSlug) ?><?php endif; ?>
      &middot; <a href="/index.php">clear</a>
    </p>
  <?php endif; ?>

  <?php if ($totalItems > 0): ?>
    <p class="result-count">
      <?= number_format($totalItems) ?> item<?= $totalItems === 1 ? '' : 's' ?>
      <?php if ($totalPages > 1): ?> — page <?= $page ?> of <?= number_format($totalPages) ?><?php endif; ?>
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

  <?php if ($totalPages > 1): ?>
    <nav class="pagination" aria-label="Pagination">
      <?php if ($page > 1): ?>
        <a href="<?= h(paginate_url($page - 1, $q, $tagSlug)) ?>">&laquo; Prev</a>
      <?php else: ?>
        <span class="pagination-disabled">&laquo; Prev</span>
      <?php endif; ?>

      <?php
        $windowStart = max(1, $page - 2);
        $windowEnd = min($totalPages, $page + 2);
      ?>
      <?php if ($windowStart > 1): ?>
        <a href="<?= h(paginate_url(1, $q, $tagSlug)) ?>">1</a>
        <?php if ($windowStart > 2): ?><span class="pagination-ellipsis">&hellip;</span><?php endif; ?>
      <?php endif; ?>

      <?php for ($p = $windowStart; $p <= $windowEnd; $p++): ?>
        <?php if ($p === $page): ?>
          <span class="pagination-current"><?= $p ?></span>
        <?php else: ?>
          <a href="<?= h(paginate_url($p, $q, $tagSlug)) ?>"><?= $p ?></a>
        <?php endif; ?>
      <?php endfor; ?>

      <?php if ($windowEnd < $totalPages): ?>
        <?php if ($windowEnd < $totalPages - 1): ?><span class="pagination-ellipsis">&hellip;</span><?php endif; ?>
        <a href="<?= h(paginate_url($totalPages, $q, $tagSlug)) ?>"><?= number_format($totalPages) ?></a>
      <?php endif; ?>

      <?php if ($page < $totalPages): ?>
        <a href="<?= h(paginate_url($page + 1, $q, $tagSlug)) ?>">Next &raquo;</a>
      <?php else: ?>
        <span class="pagination-disabled">Next &raquo;</span>
      <?php endif; ?>
    </nav>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
