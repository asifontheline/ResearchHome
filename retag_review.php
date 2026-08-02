<?php
// One-off admin utility: re-run classify_subjects() against every existing
// item's title+abstract and reconcile its taxonomy tags (subjects.php slugs
// only -- arXiv categories, Crossref/OpenAlex subjects, and manually typed
// tags are left untouched) against the current, word-boundary-matching
// version of that function. Built to fix items mistagged by the old
// substring-matching classify_subjects() (see includes/functions.php).
// Delete this file once the cleanup pass is done -- it's not linked from
// anywhere and isn't meant to stick around as a permanent feature.
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

$subjects = require __DIR__ . '/includes/subjects.php';
$taxonomySlugs = array_keys($subjects);

$apply = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['apply'] ?? '') === '1';

$items = db()->query('SELECT id, title, url, abstract, source_name FROM items')->fetchAll();
$crawledUrls = array_flip(array_column(db()->query('SELECT url FROM crawl_queue')->fetchAll(), 'url'));

$removed = [];
$added = [];
$tagCountHistogram = [];
$emptyAbstractSingleTagCount = 0;
$singleTagCount = 0;
$zeroTagFromCrawler = 0;
$zeroTagOther = 0;
$zeroTagSources = [];

foreach ($items as $item) {
    $currentTags = get_item_tags((int) $item['id']);
    $currentTaxonomyTags = array_values(array_filter(
        $currentTags,
        fn($t) => in_array($t['slug'], $taxonomySlugs, true)
    ));

    $n = count($currentTags);
    $tagCountHistogram[$n] = ($tagCountHistogram[$n] ?? 0) + 1;
    if ($n === 1) {
        $singleTagCount++;
        if (trim((string) ($item['abstract'] ?? '')) === '') {
            $emptyAbstractSingleTagCount++;
        }
    }
    if ($n === 0) {
        if (isset($crawledUrls[$item['url']])) {
            $zeroTagFromCrawler++;
        } else {
            $zeroTagOther++;
        }
        $src = $item['source_name'] ?: '(none)';
        $zeroTagSources[$src] = ($zeroTagSources[$src] ?? 0) + 1;
    }

    $newMatches = classify_subjects(trim(($item['title'] ?? '') . ' ' . ($item['abstract'] ?? '')));

    foreach ($currentTaxonomyTags as $t) {
        if (!in_array($t['slug'], $newMatches, true)) {
            $removed[] = ['item_id' => (int) $item['id'], 'title' => $item['title'], 'tag' => $t['name']];
            if ($apply) {
                db()->prepare('DELETE FROM item_tags WHERE item_id = ? AND tag_id = ?')
                    ->execute([$item['id'], $t['id']]);
            }
        }
    }

    $currentTaxonomySlugs = array_column($currentTaxonomyTags, 'slug');
    foreach (array_diff($newMatches, $currentTaxonomySlugs) as $slug) {
        $added[] = ['item_id' => (int) $item['id'], 'title' => $item['title'], 'tag' => $slug];
        if ($apply) {
            foreach (resolve_tag_ids($slug) as $tagId) {
                db()->prepare('INSERT IGNORE INTO item_tags (item_id, tag_id) VALUES (?, ?)')
                    ->execute([$item['id'], $tagId]);
            }
        }
    }
}

header('Content-Type: text/plain; charset=utf-8');
echo $apply ? "APPLIED — changes written.\n\n" : "DRY RUN — nothing written. POST apply=1 to this URL to apply.\n\n";
echo "Items scanned: " . count($items) . "\n\n";

echo "Tag-count histogram (tags per item, all tags not just taxonomy):\n";
ksort($tagCountHistogram);
foreach ($tagCountHistogram as $n => $count) {
    echo "  {$n} tag(s): {$count} item(s)\n";
}
echo "\nSingle-tag items: {$singleTagCount}, of which {$emptyAbstractSingleTagCount} have an empty/missing abstract"
    . " (classify_subjects() only has the title to work with for those, so it's much less likely to multi-match).\n\n";

$zeroTagTotal = $zeroTagFromCrawler + $zeroTagOther;
echo "Zero-tag items: {$zeroTagTotal} -- {$zeroTagFromCrawler} came from the generic seed crawler (crawl_queue),"
    . " {$zeroTagOther} came from an API source path.\n";
echo "Zero-tag items by source_name:\n";
arsort($zeroTagSources);
foreach ($zeroTagSources as $src => $count) {
    echo "  {$src}: {$count}\n";
}
echo "\n";

echo "Tags to remove (" . count($removed) . "):\n";
foreach ($removed as $r) {
    echo "  #{$r['item_id']} [-{$r['tag']}] {$r['title']}\n";
}

echo "\nTags to add (" . count($added) . "):\n";
foreach ($added as $a) {
    echo "  #{$a['item_id']} [+{$a['tag']}] {$a['title']}\n";
}
