<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$q = trim($_GET['q'] ?? '');
$tagSlug = trim($_GET['tag'] ?? '');
$sort = ($_GET['sort'] ?? '') === 'citations' ? 'citations' : 'recency';
$perPage = 24;

$joinClause = '';
$params = [];
$where = ["i.content_type = 'research'"]; // videos live in their own section, videos.php

if ($tagSlug !== '') {
    $joinClause = ' JOIN item_tags it ON it.item_id = i.id JOIN tags t ON t.id = it.tag_id';
    $where[] = 't.slug = ?';
    $params[] = $tagSlug;
}

// Tries each candidate (exact phrase -> all-words-required -> any-word,
// strictest first) against a real COUNT query and uses the first one that
// finds something -- see search_match_candidates() in functions.php. The
// broadest tier is a deliberate last resort, not dropped: showing today's
// closest available match beats a bare "0 results" while the harvester
// queue works on finding something better. $isLooseMatch flags when it
// was that broadest tier, so the results page can say so instead of
// presenting a loose match as if it were exact.
$searchMatch = null;
$isLooseMatch = false;
if ($q !== '') {
    $candidates = search_match_candidates($q);
    foreach ($candidates as $candidate) {
        $testWhere = array_merge($where, ['MATCH(i.title, i.authors, i.abstract, i.notes) AGAINST (? IN BOOLEAN MODE)']);
        $testParams = array_merge($params, [$candidate]);
        $testWhereClause = ' WHERE ' . implode(' AND ', $testWhere);
        $stmt = db()->prepare("SELECT COUNT(DISTINCT i.id) FROM items i{$joinClause}{$testWhereClause}");
        $stmt->execute($testParams);
        if ((int) $stmt->fetchColumn() > 0) {
            $searchMatch = $candidate;
            $isLooseMatch = $candidate === end($candidates) && count($candidates) > 1;
            break;
        }
    }
    if ($searchMatch !== null) {
        $where[] = 'MATCH(i.title, i.authors, i.abstract, i.notes) AGAINST (? IN BOOLEAN MODE)';
        $params[] = $searchMatch;
    } else {
        // Every candidate, including the broadest, found nothing -- a
        // genuine zero, not just a strict-tier miss.
        $where[] = '1 = 0';
    }
}

$whereClause = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$countStmt = db()->prepare("SELECT COUNT(DISTINCT i.id) FROM items i{$joinClause}{$whereClause}");
$countStmt->execute($params);
$totalItems = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalItems / $perPage));

// Every typed search-bar keyword, hit or miss — plain visibility log,
// separate from the miss-queueing below. Deliberately only the q= case
// (typed keywords), not tag/subject pill clicks — those are "selections",
// already visible via the curated subject list itself, not something
// someone typed.
if ($q !== '') {
    record_search_log($q, $totalItems);
}

// A real text search with nothing back — queue it for the harvester to try
// as a one-off keyword search (see harvest_search_misses() in harvester.php).
// Subject/tag pill clicks (a "selection", not a typed keyword) get the same
// treatment when they land on zero results — the curated subject bar shows
// every subject regardless of current count, so clicking a currently-empty
// one is a real, common miss, not a hypothetical. Queued using that
// subject's own proven search keyword (the same one run_api_harvest()
// already uses for it) when it's a curated subject, falling back to the
// tag's stored name for specialized/source-derived tags outside the
// curated list.
//
// Deliberately NOT queuing anything when $tagSlug matches neither a
// curated subject nor a real row in `tags` — every tag pill this app ever
// renders links to a slug that already exists, so that combination can
// only happen via a guessed/probed URL, never a real click. Used to fall
// back to str_replace('-', ' ', $tagSlug), which is exactly how ~20 bare
// arXiv category codes (e.g. "cs.DC" -> "cs dc") ended up queued as
// harvest keywords in one day — meaningless noise, not real demand.
if ($totalItems === 0 || $isLooseMatch) {
    if ($q !== '') {
        record_search_miss($q);
    } elseif ($tagSlug !== '') {
        $subjectDefs = get_subjects();
        if (isset($subjectDefs[$tagSlug])) {
            record_search_miss($subjectDefs[$tagSlug]['keywords'][0]);
        } else {
            $tagRow = db()->prepare('SELECT name FROM tags WHERE slug = ?');
            $tagRow->execute([$tagSlug]);
            $tagName = $tagRow->fetchColumn();
            if ($tagName) {
                record_search_miss($tagName);
            }
        }
    }
}

$page = max(1, min($totalPages, (int) ($_GET['page'] ?? 1)));
$offset = ($page - 1) * $perPage;

// citation_count is NULL for sources that don't report one (arXiv, PubMed,
// patents) — MySQL sorts NULL as lowest, so DESC naturally puts those items
// last rather than letting them crowd out ranked ones at the top.
$orderBy = $sort === 'citations' ? 'i.citation_count DESC, i.added_at DESC' : 'i.added_at DESC';
$selectFields = 'i.*';
$selectParams = $params;
if ($searchMatch !== null) {
    // Same MATCH() text search, recomputed as a plain relevance score
    // (no IN BOOLEAN MODE here — a bare AGAINST() score works for ranking
    // regardless of which mode matched the row) so a text search defaults
    // to "closest match first" instead of "newest first". An explicit
    // Most-cited sort still overrides it, same as before.
    $selectFields = 'i.*, MATCH(i.title, i.authors, i.abstract, i.notes) AGAINST (?) AS relevance';
    $selectParams = array_merge([$searchMatch], $params);
    if ($sort !== 'citations') {
        $orderBy = 'relevance DESC, i.added_at DESC';
    }
}
$sql = "SELECT DISTINCT {$selectFields} FROM items i{$joinClause}{$whereClause}
        ORDER BY {$orderBy} LIMIT {$perPage} OFFSET {$offset}";

$stmt = db()->prepare($sql);
$stmt->execute($selectParams);
$items = $stmt->fetchAll();

$grouped = get_grouped_subjects();

/** Builds a pagination link preserving the current q/tag/sort filters. */
function paginate_url(int $page, string $q, string $tagSlug, string $sort = 'recency'): string {
    $params = ['page' => $page];
    if ($q !== '') $params['q'] = $q;
    if ($tagSlug !== '') $params['tag'] = $tagSlug;
    if ($sort !== 'recency') $params['sort'] = $sort;
    return '/index.php?' . http_build_query($params);
}

/** Builds a sort-toggle link preserving the current q/tag filters, resetting to page 1. */
function sort_url(string $sort, string $q, string $tagSlug): string {
    $params = [];
    if ($q !== '') $params['q'] = $q;
    if ($tagSlug !== '') $params['tag'] = $tagSlug;
    if ($sort !== 'recency') $params['sort'] = $sort;
    return '/index.php' . ($params ? '?' . http_build_query($params) : '');
}

/** Builds a subject-pill link preserving the current q/sort filters (not the old tag). */
function tag_url(string $tagSlug, string $q, string $sort = 'recency'): string {
    $params = ['tag' => $tagSlug];
    if ($q !== '') $params['q'] = $q;
    if ($sort !== 'recency') $params['sort'] = $sort;
    return '/index.php?' . http_build_query($params);
}

$pageTitle = 'Browse';
$isHomePage = true;
require __DIR__ . '/includes/header.php';

$tickerText = 'ResHub (Research Hub) automatically discovers and catalogs freely available research — papers, patents, and articles — from arXiv, Crossref, PubMed, OpenAlex, and more. Harvested every 15 minutes, with a discovery phase every half hour looking for new sources. This is a dynamic website that discovers newly available information and adds it continuously — keep checking in and discover more!';
?>

<div class="ticker" role="note" aria-label="About ResHub">
  <div class="ticker-track">
    <span><?= h($tickerText) ?></span>
    <span aria-hidden="true"><?= h($tickerText) ?></span>
  </div>
</div>

<nav class="subject-bar">
  <div class="top-links">
    <a class="all-link active" href="/index.php">By Subject</a>
    <span class="top-links-sep">&middot;</span>
    <a class="all-link" href="/tags.php">By Tag</a>
  </div>

  <?php foreach ($grouped['groups'] as $parent => $subjects): ?>
    <div class="subject-group">
      <span class="subject-group-label"><?= h($parent) ?></span>
      <div class="subject-pills">
        <?php foreach ($subjects as $s): ?>
          <a class="tag-pill <?= $tagSlug === $s['slug'] ? 'active' : '' ?>" href="<?= h(tag_url($s['slug'], $q, $sort)) ?>">
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
  <?php if (($_GET['reported'] ?? '') === 'removed'): ?>
    <p class="muted report-result">Confirmed unreachable — removed from the catalog. Thanks for the report.</p>
  <?php endif; ?>
  <?php if ($q || $tagSlug): ?>
    <p class="filter-summary">
      <?php if ($q): ?>Search: “<?= h($q) ?>”<?php endif; ?>
      <?php if ($tagSlug): ?> Tag: <?= h($tagSlug) ?><?php endif; ?>
      &middot; <a href="/index.php">clear</a>
    </p>
  <?php endif; ?>

  <?php if ($isLooseMatch): ?>
    <p class="muted loose-match-note">
      No exact or all-words match for “<?= h($q) ?>” — showing the closest related items instead.
      We've also queued this search for the harvester to try finding something more specific.
    </p>
  <?php endif; ?>

  <?php if ($totalItems > 0): ?>
    <p class="result-count">
      <?= number_format($totalItems) ?> item<?= $totalItems === 1 ? '' : 's' ?>
      <?php if ($totalPages > 1): ?> — page <?= $page ?> of <?= number_format($totalPages) ?><?php endif; ?>
      <span class="sort-toggle">
        Sort:
        <a class="<?= $sort === 'recency' ? 'active' : '' ?>" href="<?= h(sort_url('recency', $q, $tagSlug)) ?>"><?= $q !== '' ? 'Best match' : 'Newest' ?></a>
        &middot;
        <a class="<?= $sort === 'citations' ? 'active' : '' ?>" href="<?= h(sort_url('citations', $q, $tagSlug)) ?>">Most cited</a>
        <?php if (has_video_content()): ?>
          &middot;
          <a href="/videos.php">By Video</a>
        <?php endif; ?>
      </span>
    </p>
  <?php endif; ?>

  <?php if (!$items && $q !== ''): ?>
    <div class="empty-state search-empty-state">
      <p>No matches yet for &ldquo;<?= h($q) ?>&rdquo; — we've queued this search for the harvester to try directly. Check back soon, or search these free/open portals now:</p>
      <ul class="portal-links">
        <?php foreach (external_search_portals($q) as $label => $url): ?>
          <li><a href="<?= h($url) ?>" target="_blank" rel="noopener noreferrer"><?= h($label) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php elseif (!$items): ?>
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
          <?php if ($item['citation_count'] !== null): ?><span class="citations"><?= number_format((int)$item['citation_count']) ?> citation<?= (int)$item['citation_count'] === 1 ? '' : 's' ?></span><?php endif; ?>
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
        <a href="<?= h(paginate_url($page - 1, $q, $tagSlug, $sort)) ?>">&laquo; Prev</a>
      <?php else: ?>
        <span class="pagination-disabled">&laquo; Prev</span>
      <?php endif; ?>

      <?php
        $windowStart = max(1, $page - 2);
        $windowEnd = min($totalPages, $page + 2);
      ?>
      <?php if ($windowStart > 1): ?>
        <a href="<?= h(paginate_url(1, $q, $tagSlug, $sort)) ?>">1</a>
        <?php if ($windowStart > 2): ?><span class="pagination-ellipsis">&hellip;</span><?php endif; ?>
      <?php endif; ?>

      <?php for ($p = $windowStart; $p <= $windowEnd; $p++): ?>
        <?php if ($p === $page): ?>
          <span class="pagination-current"><?= $p ?></span>
        <?php else: ?>
          <a href="<?= h(paginate_url($p, $q, $tagSlug, $sort)) ?>"><?= $p ?></a>
        <?php endif; ?>
      <?php endfor; ?>

      <?php if ($windowEnd < $totalPages): ?>
        <?php if ($windowEnd < $totalPages - 1): ?><span class="pagination-ellipsis">&hellip;</span><?php endif; ?>
        <a href="<?= h(paginate_url($totalPages, $q, $tagSlug, $sort)) ?>"><?= number_format($totalPages) ?></a>
      <?php endif; ?>

      <?php if ($page < $totalPages): ?>
        <a href="<?= h(paginate_url($page + 1, $q, $tagSlug, $sort)) ?>">Next &raquo;</a>
      <?php else: ?>
        <span class="pagination-disabled">Next &raquo;</span>
      <?php endif; ?>
    </nav>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
