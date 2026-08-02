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

$items = db()->query('SELECT id, title, abstract FROM items')->fetchAll();

$removed = [];
$added = [];

foreach ($items as $item) {
    $currentTags = get_item_tags((int) $item['id']);
    $currentTaxonomyTags = array_values(array_filter(
        $currentTags,
        fn($t) => in_array($t['slug'], $taxonomySlugs, true)
    ));

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

echo "Tags to remove (" . count($removed) . "):\n";
foreach ($removed as $r) {
    echo "  #{$r['item_id']} [-{$r['tag']}] {$r['title']}\n";
}

echo "\nTags to add (" . count($added) . "):\n";
foreach ($added as $a) {
    echo "  #{$a['item_id']} [+{$a['tag']}] {$a['title']}\n";
}
