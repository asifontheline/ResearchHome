<?php
require_once __DIR__ . '/db.php';

const HARVEST_USER_AGENT_BASE = 'ResHubBot/1.0 (+personal research catalog; respects robots.txt)';
define('HARVEST_USER_AGENT', HARVEST_USER_AGENT_BASE . (defined('CONTACT_EMAIL') && CONTACT_EMAIL !== 'you@example.com' ? '; contact: ' . CONTACT_EMAIL : ''));

function slugify(string $text): string {
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-');
}

function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Get or create tag ids for a comma-separated tag string, and return the ids.
 */
function resolve_tag_ids(string $tagsCsv): array {
    $names = array_filter(array_map('trim', explode(',', $tagsCsv)));
    $ids = [];
    $pdo = db();
    foreach ($names as $name) {
        if ($name === '') continue;
        $slug = slugify($name);
        if ($slug === '') continue;
        $stmt = $pdo->prepare('SELECT id FROM tags WHERE slug = ?');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        if ($row) {
            $ids[] = (int)$row['id'];
        } else {
            $ins = $pdo->prepare('INSERT INTO tags (name, slug) VALUES (?, ?)');
            $ins->execute([$name, $slug]);
            $ids[] = (int)$pdo->lastInsertId();
        }
    }
    return array_unique($ids);
}

function set_item_tags(int $itemId, array $tagIds): void {
    $pdo = db();
    $pdo->prepare('DELETE FROM item_tags WHERE item_id = ?')->execute([$itemId]);
    $stmt = $pdo->prepare('INSERT INTO item_tags (item_id, tag_id) VALUES (?, ?)');
    foreach ($tagIds as $tagId) {
        $stmt->execute([$itemId, $tagId]);
    }
}

function get_item_tags(int $itemId): array {
    $stmt = db()->prepare(
        'SELECT t.id, t.name, t.slug FROM tags t
         JOIN item_tags it ON it.tag_id = t.id
         WHERE it.item_id = ? ORDER BY t.name'
    );
    $stmt->execute([$itemId]);
    return $stmt->fetchAll();
}

/**
 * Every distinct source_name with a successful item in the catalog —
 * i.e. every source we've actually pulled content from, not just the 6
 * core API mechanisms that do the pulling. Matches the "Sources" count in
 * get_catalog_summary() exactly (both count DISTINCT source_name on items).
 */
function all_contributing_sources(): array {
    return db()->query(
        "SELECT source_name, COUNT(*) AS item_count
         FROM items
         WHERE source_name IS NOT NULL
         GROUP BY source_name
         ORDER BY item_count DESC, source_name ASC"
    )->fetchAll();
}

function all_tags_with_counts(): array {
    return db()->query(
        'SELECT t.id, t.name, t.slug, COUNT(it.item_id) AS item_count
         FROM tags t
         LEFT JOIN item_tags it ON it.tag_id = t.id
         GROUP BY t.id
         ORDER BY t.name'
    )->fetchAll();
}

function get_catalog_summary(): array {
    return [
        'total_items' => (int) db()->query('SELECT COUNT(*) FROM items')->fetchColumn(),
        'total_tags' => (int) db()->query('SELECT COUNT(*) FROM tags')->fetchColumn(),
        'total_sources' => (int) db()->query("SELECT COUNT(DISTINCT source_name) FROM items WHERE source_name IS NOT NULL")->fetchColumn(),
        'last_run' => db()->query('SELECT * FROM harvest_log ORDER BY started_at DESC LIMIT 1')->fetch() ?: null,
    ];
}

// ---- Small key-value settings store (harvester state) -------------------

function get_setting(string $name, ?string $default = null): ?string {
    $stmt = db()->prepare('SELECT value FROM settings WHERE name = ?');
    $stmt->execute([$name]);
    $row = $stmt->fetch();
    return $row ? $row['value'] : $default;
}

function set_setting(string $name, string $value): void {
    db()->prepare(
        'INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)'
    )->execute([$name, $value]);
}

// ---- Item insertion (shared by manual add/edit and the harvester) -------

function url_hash(string $url): string {
    return hash('sha256', $url);
}

/**
 * Insert an item if its URL hasn't been seen before. Returns the item id,
 * or null if it was already present (dedup by url_hash).
 */
function insert_item_if_new(array $fields, array $tagNames = []): ?int {
    // One captured connection for the whole operation — lastInsertId() is
    // only valid on the exact connection that did the INSERT, so this must
    // not call db() again in between (a reconnect elsewhere swapping the
    // static instance would otherwise make lastInsertId() return 0, which
    // then corrupts item_tags with a foreign-key-violating row).
    $pdo = db();

    $hash = url_hash($fields['url']);
    $exists = $pdo->prepare('SELECT id FROM items WHERE url_hash = ?');
    $exists->execute([$hash]);
    if ($exists->fetch()) {
        return null;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO items (title, url, url_hash, authors, abstract, notes, source_name, published_date, image_url)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        mb_strimwidth($fields['title'] ?? 'Untitled', 0, 512, ''),
        $fields['url'],
        $hash,
        $fields['authors'] ?? null,
        $fields['abstract'] ?? null,
        $fields['notes'] ?? null,
        $fields['source_name'] ?? null,
        $fields['published_date'] ?? null,
        $fields['image_url'] ?? null,
    ]);
    $itemId = (int) $pdo->lastInsertId();
    if ($itemId <= 0) {
        throw new RuntimeException('insert_item_if_new: lastInsertId() returned 0 after a successful insert — refusing to write item_tags against an invalid id.');
    }

    if ($tagNames) {
        set_item_tags($itemId, resolve_tag_ids(implode(',', $tagNames)));
    }

    return $itemId;
}

/**
 * Match subject keywords against a blob of text (title + abstract) and
 * return the slugs of subjects whose keywords appear.
 */
function classify_subjects(string $text): array {
    static $subjects = null;
    if ($subjects === null) {
        $subjects = require __DIR__ . '/subjects.php';
    }
    $text = mb_strtolower($text);
    $matches = [];
    foreach ($subjects as $slug => $def) {
        foreach ($def['keywords'] as $kw) {
            if (mb_strpos($text, mb_strtolower($kw)) !== false) {
                $matches[] = $slug;
                break;
            }
        }
    }
    return $matches;
}

function subject_label(?string $slug): string {
    if ($slug === null) return 'General';
    static $subjects = null;
    if ($subjects === null) {
        $subjects = require __DIR__ . '/subjects.php';
    }
    return $subjects[$slug]['label'] ?? $slug;
}

/**
 * Curated subjects (from subjects.php) grouped under their parent category,
 * each annotated with its live item count.
 *
 * Tags outside subjects.php (arXiv category codes, Crossref subject
 * strings, OpenAlex topic names, ad-hoc manual tags, ...) are NOT surfaced
 * as an ever-growing pile of individually-named pills — a page full of
 * one-off source-classification strings reads as noise, not navigation.
 * Instead:
 *  - ones that have accumulated more than OTHER_TAG_PROMOTION_THRESHOLD
 *    items are real signal, not noise — but only the top
 *    SPECIALIZED_TOPICS_SHOWN of those get their own named pill. Everything
 *    past that folds into a single "+N more" link to the full tag
 *    directory (tags.php) rather than fragmenting the subject bar further.
 *  - everything at or below the threshold is dropped from display
 *    entirely. Those items aren't lost — insert_item_if_new() always
 *    tags an item with a real curated/classified subject alongside any
 *    source-specific code, so a low-count code is redundant with a
 *    subject tag the item already carries.
 */
const OTHER_TAG_PROMOTION_THRESHOLD = 2;
const SPECIALIZED_TOPICS_SHOWN = 2;

function get_grouped_subjects(): array {
    $subjects = require __DIR__ . '/subjects.php';
    $counts = [];
    foreach (all_tags_with_counts() as $t) {
        $counts[$t['slug']] = (int) $t['item_count'];
    }

    $groups = [];
    foreach ($subjects as $slug => $def) {
        $parent = $def['parent'] ?? 'Other';
        $groups[$parent][] = [
            'slug' => $slug,
            'label' => $def['label'],
            'count' => $counts[$slug] ?? 0,
        ];
    }

    $knownSlugs = array_keys($subjects);
    $specialized = [];
    foreach (all_tags_with_counts() as $t) {
        if (!in_array($t['slug'], $knownSlugs, true) && (int) $t['item_count'] > OTHER_TAG_PROMOTION_THRESHOLD) {
            $specialized[] = ['slug' => $t['slug'], 'label' => $t['name'], 'count' => (int) $t['item_count']];
        }
    }
    usort($specialized, fn($a, $b) => $b['count'] <=> $a['count']);

    $overflowCount = 0;
    if ($specialized) {
        $overflowCount = max(0, count($specialized) - SPECIALIZED_TOPICS_SHOWN);
        $groups['Specialized Topics'] = array_slice($specialized, 0, SPECIALIZED_TOPICS_SHOWN);
    }

    return ['groups' => $groups, 'overflow_count' => $overflowCount];
}

/**
 * Items added per day, broken down by source, for the activity chart on
 * harvest_log.php. Only the top 6 sources by volume get their own series —
 * everything else folds into "Other" so the chart/legend stays readable
 * (see the dataviz skill's categorical-palette cap).
 */
function get_harvest_activity_by_source(int $days = 30): array {
    $rows = db()->query(
        "SELECT DATE(added_at) AS day, COALESCE(source_name, 'Unknown') AS source, COUNT(*) AS cnt
         FROM items
         WHERE added_at >= DATE_SUB(CURDATE(), INTERVAL " . (int)$days . " DAY)
         GROUP BY day, source"
    )->fetchAll();

    $totals = [];
    foreach ($rows as $r) {
        $totals[$r['source']] = ($totals[$r['source']] ?? 0) + (int) $r['cnt'];
    }
    arsort($totals);
    $topSources = array_slice(array_keys($totals), 0, 6);

    $dayList = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $dayList[] = date('Y-m-d', strtotime("-{$i} days"));
    }

    $seriesKeys = array_merge($topSources, ['Other']);
    $matrix = [];
    foreach ($dayList as $day) {
        $matrix[$day] = array_fill_keys($seriesKeys, 0);
    }
    foreach ($rows as $r) {
        if (!isset($matrix[$r['day']])) continue; // shouldn't happen, defensive
        $key = in_array($r['source'], $topSources, true) ? $r['source'] : 'Other';
        $matrix[$r['day']][$key] += (int) $r['cnt'];
    }

    $hasOther = false;
    foreach ($matrix as $day => $sources) {
        if ($sources['Other'] > 0) { $hasOther = true; break; }
    }
    $series = $hasOther ? $seriesKeys : $topSources;

    return ['days' => $dayList, 'series' => $series, 'matrix' => $matrix];
}

// ---- Activity chart (inline SVG, no JS dependency, no external libs) ----
//
// Colors reference --series-0..5 / --series-other CSS custom properties
// (assets/style.css) — the validated categorical palette (dataviz skill,
// references/palette.md), fixed hue order, light/dark pair per slot.
// "Other" gets neutral gray, not a categorical hue, since it's a catch-all
// rather than a real entity.

function render_activity_chart(array $data): string {
    $days = $data['days'];
    $series = $data['series'];
    $matrix = $data['matrix'];

    if (!$days || !$series) {
        return '<p class="muted">No harvest activity yet.</p>';
    }

    $barW = 18;
    $gap = 4;
    $chartH = 200;
    $leftPad = 40;
    $bottomPad = 30;
    $chartW = count($days) * ($barW + $gap);
    $svgW = $chartW + $leftPad + 10;
    $svgH = $chartH + $bottomPad + 10;

    $dayTotals = array_map(fn($d) => array_sum($matrix[$d]), $days);
    $maxTotal = max(1, max($dayTotals));
    // round the axis max up to a clean step
    $step = max(1, (int) ceil($maxTotal / 4));
    $niceMax = $step * 4;

    $bars = '';
    $x = $leftPad;
    foreach ($days as $di => $day) {
        $y = $chartH;
        foreach ($series as $si => $s) {
            $count = $matrix[$day][$s];
            if ($count <= 0) continue;
            $segH = ($count / $niceMax) * $chartH;
            $y -= $segH;
            $varName = $s === 'Other' ? '--series-other' : "--series-{$si}";
            // 1px surface-gap top/bottom separates touching stacked segments
            // (see dataviz skill marks-and-anatomy.md) without a border stroke.
            $bars .= sprintf(
                '<rect class="chart-seg" x="%.1f" y="%.1f" width="%d" height="%.1f" style="fill: var(%s)"><title>%s: %d on %s</title></rect>',
                $x, $y + 0.5, $barW, max(0.5, $segH - 1), $varName, h($s), $count, h($day)
            );
        }
        // x-axis label every ~5th bar (or fewer bars) to avoid crowding
        if (count($days) <= 10 || $di % 5 === 0 || $di === count($days) - 1) {
            $bars .= sprintf(
                '<text x="%.1f" y="%d" class="chart-axis-label" text-anchor="middle">%s</text>',
                $x + $barW / 2, $chartH + 16, h(substr($day, 5))
            );
        }
        $x += $barW + $gap;
    }

    // y-axis gridlines + labels
    $gridlines = '';
    for ($i = 0; $i <= 4; $i++) {
        $val = (int) ($niceMax * $i / 4);
        $gy = $chartH - ($chartH * $i / 4);
        $gridlines .= sprintf(
            '<line x1="%d" y1="%.1f" x2="%d" y2="%.1f" class="chart-grid" />
             <text x="%d" y="%.1f" class="chart-axis-label" text-anchor="end">%d</text>',
            $leftPad, $gy, $svgW - 10, $gy, $leftPad - 6, $gy + 4, $val
        );
    }

    $svg = sprintf(
        '<svg viewBox="0 0 %d %d" class="activity-chart" role="img" aria-label="Items added per day by source">%s%s</svg>',
        $svgW, $svgH, $gridlines, $bars
    );

    $legend = '<div class="chart-legend">';
    foreach ($series as $si => $s) {
        $varName = $s === 'Other' ? '--series-other' : "--series-{$si}";
        $legend .= sprintf(
            '<span class="chart-legend-item"><span class="chart-swatch" style="background: var(%s)"></span>%s</span>',
            $varName, h($s)
        );
    }
    $legend .= '</div>';

    // Table view fallback (accessibility — same data, always available)
    $table = '<details class="chart-table-toggle"><summary>View as table</summary><table class="seed-table"><thead><tr><th>Date</th>';
    foreach ($series as $s) $table .= '<th>' . h($s) . '</th>';
    $table .= '</tr></thead><tbody>';
    foreach ($days as $day) {
        if (array_sum($matrix[$day]) === 0) continue;
        $table .= '<tr><td>' . h($day) . '</td>';
        foreach ($series as $s) $table .= '<td>' . (int) $matrix[$day][$s] . '</td>';
        $table .= '</tr>';
    }
    $table .= '</tbody></table></details>';

    return '<div class="chart-scroll">' . $svg . '</div>' . $legend . $table;
}

// ---- URL fetching -------------------------------------------------------

function is_safe_target_url(string $url): bool {
    $parts = parse_url($url);
    if (!$parts || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
        return false;
    }
    $host = $parts['host'] ?? '';
    $ip = gethostbyname($host);
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

/**
 * Lightweight reachability check (HEAD, falling back to a ranged GET if the
 * server rejects HEAD) — used by the link-health pass to decide whether an
 * item's outbound link is still alive. Returns the final HTTP status code
 * after following redirects, or null if the request couldn't complete at all
 * (DNS failure, connection refused, timeout).
 */
function check_url_status(string $url): ?int {
    if (!is_safe_target_url($url)) return null;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => HARVEST_USER_AGENT,
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($code === 0) {
        // Some servers reject HEAD outright; retry with a minimal ranged GET.
        curl_setopt_array($ch, [CURLOPT_NOBODY => false, CURLOPT_RANGE => '0-0']);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    }

    return $code > 0 ? $code : null;
}

function safe_http_get(string $url, array $headers = []): ?string {
    if (!is_safe_target_url($url)) {
        return null;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'ResHub/1.0 (+metadata fetcher)',
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($body === false || $code >= 400) {
        return null;
    }
    return $body;
}

// ---- Source detection & metadata extraction ------------------------------

function fetch_metadata_for_url(string $url): array {
    $result = [
        'title' => null, 'authors' => null, 'abstract' => null,
        'published_date' => null, 'source_name' => null, 'image_url' => null,
    ];

    if (preg_match('#arxiv\.org/(?:abs|pdf)/([\w.\-/]+?)(?:v\d+)?(?:\.pdf)?$#i', $url, $m)) {
        return fetch_arxiv($m[1]) ?: $result;
    }
    if (preg_match('#pubmed\.ncbi\.nlm\.nih\.gov/(\d+)#i', $url, $m)) {
        return fetch_pubmed($m[1]) ?: $result;
    }
    if (preg_match('#doi\.org/(10\.\S+)#i', $url, $m)) {
        return fetch_crossref($m[1]) ?: fetch_generic($url) ?: $result;
    }

    return fetch_generic($url) ?: $result;
}

function fetch_arxiv(string $arxivId): ?array {
    $body = safe_http_get('http://export.arxiv.org/api/query?id_list=' . urlencode($arxivId));
    if (!$body) return null;
    $xml = @simplexml_load_string($body);
    if (!$xml || !isset($xml->entry)) return null;
    $entry = $xml->entry;
    $authors = [];
    foreach ($entry->author as $a) {
        $authors[] = (string)$a->name;
    }
    return [
        'title' => trim((string)$entry->title),
        'authors' => implode(', ', $authors),
        'abstract' => trim((string)$entry->summary),
        'published_date' => date('Y-m-d', strtotime((string)$entry->published)),
        'source_name' => 'arXiv',
        'image_url' => null,
    ];
}

function fetch_pubmed(string $pmid): ?array {
    $key = defined('NCBI_API_KEY') && NCBI_API_KEY ? '&api_key=' . urlencode(NCBI_API_KEY) : '';
    $contact = defined('CONTACT_EMAIL') ? '&tool=researchhome&email=' . urlencode(CONTACT_EMAIL) : '';
    $body = safe_http_get("https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esummary.fcgi?db=pubmed&id={$pmid}&retmode=json{$key}{$contact}");
    if (!$body) return null;
    $data = json_decode($body, true);
    $doc = $data['result'][$pmid] ?? null;
    if (!$doc) return null;
    $authors = array_map(fn($a) => $a['name'] ?? '', $doc['authors'] ?? []);
    return [
        'title' => $doc['title'] ?? null,
        'authors' => implode(', ', array_filter($authors)),
        'abstract' => null, // esummary doesn't include abstract; user can fill via efetch if needed
        'published_date' => isset($doc['pubdate']) ? date('Y-m-d', strtotime($doc['pubdate'])) : null,
        'source_name' => 'PubMed',
        'image_url' => null,
    ];
}

function fetch_crossref(string $doi): ?array {
    $body = safe_http_get('https://api.crossref.org/works/' . urlencode($doi));
    if (!$body) return null;
    $data = json_decode($body, true);
    $msg = $data['message'] ?? null;
    if (!$msg) return null;
    $authors = array_map(function ($a) {
        return trim(($a['given'] ?? '') . ' ' . ($a['family'] ?? ''));
    }, $msg['author'] ?? []);
    $dateParts = $msg['published']['date-parts'][0] ?? null;
    $published = $dateParts ? implode('-', array_pad($dateParts, 3, '01')) : null;
    return [
        'title' => $msg['title'][0] ?? null,
        'authors' => implode(', ', array_filter($authors)),
        'abstract' => isset($msg['abstract']) ? strip_tags($msg['abstract']) : null,
        'published_date' => $published,
        'source_name' => $msg['publisher'] ?? 'Crossref',
        'image_url' => null,
    ];
}

function fetch_generic(string $url): ?array {
    $body = safe_http_get($url);
    if (!$body) return null;
    return extract_generic_metadata($body, $url);
}

/**
 * Split out from fetch_generic() so callers that already have the raw HTML
 * (e.g. the crawler, which also needs the body to look for outbound links)
 * can reuse it instead of fetching the same page twice.
 */
function extract_generic_metadata(string $body, string $url): array {
    $get_meta = function (string $name) use ($body): ?string {
        if (preg_match('#<meta[^>]+(?:property|name)=["\']' . preg_quote($name, '#') . '["\'][^>]+content=["\']([^"\']*)["\']#i', $body, $m)) {
            return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        }
        if (preg_match('#<meta[^>]+content=["\']([^"\']*)["\'][^>]+(?:property|name)=["\']' . preg_quote($name, '#') . '["\']#i', $body, $m)) {
            return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        }
        return null;
    };

    $title = $get_meta('og:title');
    if (!$title && preg_match('#<title[^>]*>(.*?)</title>#is', $body, $m)) {
        $title = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
    }

    $host = parse_url($url, PHP_URL_HOST) ?: 'Web';
    $sourceName = str_contains($host, 'patents.google.com') ? 'Google Patents' : $host;

    return [
        'title' => $title,
        'authors' => $get_meta('author') ?? $get_meta('citation_author'),
        'abstract' => $get_meta('og:description') ?? $get_meta('description'),
        'published_date' => ($d = $get_meta('article:published_time') ?? $get_meta('citation_publication_date')) ? date('Y-m-d', strtotime($d)) : null,
        'source_name' => $sourceName,
        'image_url' => $get_meta('og:image'),
    ];
}
