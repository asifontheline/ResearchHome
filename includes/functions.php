<?php
require_once __DIR__ . '/db.php';

const HARVEST_USER_AGENT_BASE = 'ResHubBot/1.0 (+personal research catalog; respects robots.txt)';
define('HARVEST_USER_AGENT', HARVEST_USER_AGENT_BASE . (defined('CONTACT_EMAIL') && CONTACT_EMAIL !== 'you@example.com' ? '; contact: ' . CONTACT_EMAIL : ''));

/**
 * IP -> country/region/city via ip-api.com's free tier (no key, HTTP only,
 * 45 req/min). Short timeout so a slow/down geo API can't stall a page
 * load by more than a beat — on any failure this just returns null and the
 * page view is still recorded without a location. The IP passed in is
 * never persisted by the caller; only the resolved names are stored.
 */
function geolocate_ip(string $ip): ?array {
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return null; // private/loopback/reserved — nothing to look up (local dev, etc.)
    }
    $ch = curl_init("http://ip-api.com/json/{$ip}?fields=status,country,regionName,city,lat,lon");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 2,
        CURLOPT_USERAGENT => 'ResHub/1.0',
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    if (!$body) return null;
    $data = json_decode($body, true);
    if (!is_array($data) || ($data['status'] ?? '') !== 'success') return null;
    return [
        'country' => $data['country'] ?? null,
        'region' => $data['regionName'] ?? null,
        'city' => $data['city'] ?? null,
        'lat' => $data['lat'] ?? null,
        'lon' => $data['lon'] ?? null,
    ];
}

/**
 * MVP page-view logging — deliberately small (per explicit instruction:
 * build minimal, keep only if it proves useful, stall further effort
 * otherwise). Called from header.php, public pages only (admin's own
 * usage while logged in isn't visitor traffic). No raw IP stored — see
 * page_views table comment in sql/schema.sql for the hashing rationale.
 * Best-effort bot filtering by User-Agent; not airtight, not the point —
 * this is for a rough sense of traffic, not ad-tech-grade analytics.
 */
function record_page_view(): void {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if ($ua === '' || preg_match('/bot|crawl|spider|slurp|facebookexternalhit|whatsapp|preview/i', $ua)) {
        return;
    }

    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $itemId = null;
    if ($path === '/item.php' && isset($_GET['id'])) {
        $itemId = (int) $_GET['id'];
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $salt = defined('APP_SECRET') ? APP_SECRET : 'no-secret';
    $visitorHash = hash('sha256', $ip . $salt . date('Y-m-d'));

    $referrerHost = null;
    if (!empty($_SERVER['HTTP_REFERER'])) {
        $refHost = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_HOST);
        // Don't credit ourselves as a "referrer" for internal navigation.
        if ($refHost && $refHost !== ($_SERVER['HTTP_HOST'] ?? '')) {
            $referrerHost = mb_strimwidth($refHost, 0, 255, '');
        }
    }

    try {
        db()->prepare(
            'INSERT INTO page_views (path, item_id, visitor_hash, referrer_host) VALUES (?, ?, ?, ?)'
        )->execute([mb_strimwidth($path, 0, 255, ''), $itemId, $visitorHash, $referrerHost]);

        // One geo lookup per unique visitor per day — geo_cache is keyed by
        // the same rotating hash, so repeat page views from the same
        // visitor today skip the network call entirely.
        $cached = db()->prepare('SELECT 1 FROM geo_cache WHERE visitor_hash = ?');
        $cached->execute([$visitorHash]);
        if (!$cached->fetch()) {
            $geo = geolocate_ip($ip);
            db()->prepare(
                'INSERT IGNORE INTO geo_cache (visitor_hash, country, region, city, lat, lon) VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                $visitorHash, $geo['country'] ?? null, $geo['region'] ?? null, $geo['city'] ?? null,
                $geo['lat'] ?? null, $geo['lon'] ?? null,
            ]);
        }
    } catch (Throwable $e) {
        // Never let analytics logging break an actual page load.
    }
}

/**
 * A public search returned nothing. Queue it so the next harvest run(s)
 * can try it as a one-off keyword search across the API sources — see
 * harvest_search_misses() in harvester.php. Dedup by lowercased query via
 * query_hash; repeats just bump search_count, which the harvester uses to
 * prioritize the most-requested misses first.
 */
function record_search_miss(string $q): void {
    $q = trim(mb_strimwidth($q, 0, 255, ''));
    if ($q === '') return;
    $hash = hash('sha256', mb_strtolower($q));
    try {
        db()->prepare(
            'INSERT INTO search_misses (query, query_hash) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE search_count = search_count + 1, last_searched_at = NOW()'
        )->execute([$q, $hash]);
    } catch (Throwable $e) {
        // Never let this break the search page itself.
    }
}

/**
 * Direct search links on other free/open portals, for when we genuinely
 * have nothing yet — a positive next step instead of a dead end while the
 * queued search above waits for the next harvest run.
 */
function external_search_portals(string $q): array {
    $enc = urlencode($q);
    return [
        'Google Scholar' => "https://scholar.google.com/scholar?q={$enc}",
        'Semantic Scholar' => "https://www.semanticscholar.org/search?q={$enc}&sort=relevance",
        'OpenAlex' => "https://openalex.org/works?search={$enc}",
        'arXiv' => "https://arxiv.org/search/?query={$enc}&searchtype=all",
        'PubMed' => "https://pubmed.ncbi.nlm.nih.gov/?term={$enc}",
        'CORE' => "https://core.ac.uk/search?q={$enc}",
        'BASE' => "https://www.base-search.net/Search/Results?lookfor={$enc}",
    ];
}

function get_traffic_summary(): array {
    $windows = ['today' => 'CURDATE()', 'last_7_days' => 'DATE_SUB(NOW(), INTERVAL 7 DAY)', 'last_30_days' => 'DATE_SUB(NOW(), INTERVAL 30 DAY)'];
    $summary = [];
    foreach ($windows as $key => $since) {
        $row = db()->query(
            "SELECT COUNT(*) AS views, COUNT(DISTINCT visitor_hash) AS unique_visitors
             FROM page_views WHERE viewed_at >= {$since}"
        )->fetch();
        $summary[$key] = ['views' => (int) $row['views'], 'unique_visitors' => (int) $row['unique_visitors']];
    }

    $summary['top_pages'] = db()->query(
        "SELECT path, COUNT(*) AS views FROM page_views
         WHERE viewed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
         GROUP BY path ORDER BY views DESC LIMIT 10"
    )->fetchAll();

    $summary['top_items'] = db()->query(
        "SELECT pv.item_id, i.title, COUNT(*) AS views FROM page_views pv
         JOIN items i ON i.id = pv.item_id
         WHERE pv.viewed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND pv.item_id IS NOT NULL
         GROUP BY pv.item_id ORDER BY views DESC LIMIT 10"
    )->fetchAll();

    $summary['top_referrers'] = db()->query(
        "SELECT referrer_host, COUNT(*) AS views FROM page_views
         WHERE viewed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND referrer_host IS NOT NULL
         GROUP BY referrer_host ORDER BY views DESC LIMIT 10"
    )->fetchAll();

    $summary['top_locations'] = db()->query(
        "SELECT g.country, g.region, g.city, COUNT(*) AS views, COUNT(DISTINCT pv.visitor_hash) AS unique_visitors
         FROM page_views pv
         JOIN geo_cache g ON g.visitor_hash = pv.visitor_hash
         WHERE pv.viewed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND g.country IS NOT NULL
         GROUP BY g.country, g.region, g.city
         ORDER BY views DESC LIMIT 20"
    )->fetchAll();

    return $summary;
}

/**
 * Visit counts grouped by rounded lat/lon (1 decimal, ~11km) so nearby
 * visitors within the same city collapse into one dot instead of a
 * scatter of overlapping points at the same city center. $window is
 * 'today' or 'all'.
 */
function get_traffic_map_points(string $window): array {
    $since = $window === 'today' ? "WHERE pv.viewed_at >= CURDATE()" : '';
    return db()->query(
        "SELECT ROUND(g.lat, 1) AS lat, ROUND(g.lon, 1) AS lon,
                MAX(g.city) AS city, MAX(g.country) AS country,
                COUNT(*) AS views, COUNT(DISTINCT pv.visitor_hash) AS unique_visitors
         FROM page_views pv
         JOIN geo_cache g ON g.visitor_hash = pv.visitor_hash
         {$since}
         " . ($window === 'today' ? 'AND' : 'WHERE') . " g.lat IS NOT NULL AND g.lon IS NOT NULL
         GROUP BY ROUND(g.lat, 1), ROUND(g.lon, 1)
         ORDER BY views DESC"
    )->fetchAll();
}

/**
 * Renders traffic points as dots over assets/world-map.svg (CC BY-SA 3.0,
 * Al MacDonald / Fritz Lekschas — see the file's own <desc> for full
 * attribution). Uses a linear equirectangular fit calibrated against that
 * specific file's coordinate space (derived from known reference points,
 * e.g. UK/Australia bounding-box centers) — not a general lat/lon formula,
 * only valid for this exact SVG's viewBox.
 */
function latlon_to_map_xy(float $lat, float $lon): array {
    $x = 2.3289 * $lon + 406.446;
    $y = -3.0583 * $lat + 547.114;
    // Clamp to the map's own viewBox so a stray/erroneous coordinate can't
    // draw a dot floating off in blank space outside the artwork.
    $x = max(30.767, min(814.844, $x));
    $y = max(241.591, min(700.218, $y));
    return [$x, $y];
}

function render_world_map(array $points, string $domId): string {
    if (!$points) {
        return '<p class="muted">No geolocated visits yet.</p>';
    }
    $maxViews = max(array_column($points, 'views'));
    $dots = '';
    foreach ($points as $p) {
        if ($p['lat'] === null || $p['lon'] === null) continue;
        [$x, $y] = latlon_to_map_xy((float)$p['lat'], (float)$p['lon']);
        // sqrt scaling: area (not radius) proportional to views, so one
        // outlier city doesn't visually swallow the rest of the map.
        $r = 2.5 + 7 * sqrt($p['views'] / $maxViews);
        $label = trim(($p['city'] ?? '') . ', ' . ($p['country'] ?? ''), ', ');
        $dots .= sprintf(
            '<circle cx="%.2f" cy="%.2f" r="%.2f" class="geo-dot"><title>%s: %d view%s</title></circle>',
            $x, $y, $r, h($label ?: 'Unknown'), (int)$p['views'], (int)$p['views'] === 1 ? '' : 's'
        );
    }
    // Zoom via CSS transform: scale on a fixed-px-width inner wrap, inside a
    // scrolling viewport — no map/JS library, just enough vanilla JS to move
    // a scale number and let native scrollbars handle panning once zoomed.
    return sprintf(
        '<div class="map-toolbar" data-map="%1$s">
            <button type="button" class="map-zoom-btn" data-zoom="-1" aria-label="Zoom out">&minus;</button>
            <span class="map-zoom-level" id="%1$s-level">100%%</span>
            <button type="button" class="map-zoom-btn" data-zoom="1" aria-label="Zoom in">+</button>
            <button type="button" class="map-zoom-reset">Reset</button>
        </div>
        <div class="world-map-viewport" id="%1$s-viewport">
            <div class="world-map-wrap" id="%1$s-wrap">
                <img src="/assets/world-map.svg" class="world-map-bg" alt="World map" width="784" height="459" loading="lazy">
                <svg class="world-map-dots" viewBox="30.767 241.591 784.077 458.627" preserveAspectRatio="xMidYMid meet" aria-hidden="true">%2$s</svg>
            </div>
        </div>
        <p class="muted map-credit">Map: Al MacDonald / Fritz Lekschas, CC BY-SA 3.0. Dot size ~ views, city-level precision.</p>
        <script>
        (function () {
            var id = %3$s;
            var wrap = document.getElementById(id + "-wrap");
            var level = document.getElementById(id + "-level");
            var toolbar = document.querySelector(\'.map-toolbar[data-map="\' + id + \'"]\');
            var zoom = 1;
            function apply() {
                wrap.style.transform = "scale(" + zoom + ")";
                level.textContent = Math.round(zoom * 100) + "%%";
            }
            toolbar.querySelectorAll(".map-zoom-btn").forEach(function (btn) {
                btn.addEventListener("click", function () {
                    zoom = Math.max(1, Math.min(4, zoom + parseFloat(btn.dataset.zoom) * 0.5));
                    apply();
                });
            });
            toolbar.querySelector(".map-zoom-reset").addEventListener("click", function () {
                zoom = 1;
                apply();
            });
        })();
        </script>',
        h($domId), $dots, json_encode($domId)
    );
}

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
 * or null if it was already present (dedup by url_hash) or the title is
 * junk (see is_junk_title() — checked here, centrally, so it applies
 * regardless of which source/path the item came through, not just the
 * crawler's own generic-HTML-metadata path).
 */
function insert_item_if_new(array $fields, array $tagNames = []): ?int {
    if (is_junk_title($fields['title'] ?? '')) {
        return null;
    }

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
        'INSERT INTO items (title, url, url_hash, authors, abstract, notes, source_name, published_date, image_url, citation_count)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
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
        $fields['citation_count'] ?? null,
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
 * "cs.LG" -> "Machine Learning (cs.LG)" — see includes/arxiv_categories.php
 * for the rationale. Unknown codes (arXiv adds new ones occasionally) fall
 * back to the raw code rather than erroring.
 */
function arxiv_category_label(string $code): string {
    static $map = null;
    if ($map === null) {
        $map = require __DIR__ . '/arxiv_categories.php';
    }
    return isset($map[$code]) ? "{$map[$code]} ({$code})" : $code;
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

/**
 * Institutional repositories often serve a real <title> tag for records
 * that are embargoed, under review, or otherwise have no actual metadata
 * yet — "Title Pending" (University of Wollongong Library and others),
 * "Untitled", a bare 404/access-denied page, etc. These aren't failed
 * fetches (the HTTP request succeeds), so link-health checks wouldn't
 * catch them — this is checked at crawl time instead, before the item
 * ever gets inserted.
 */
function is_junk_title(string $title): bool {
    $normalized = trim(mb_strtolower($title));
    if (mb_strlen($normalized) < 3) return true;
    $junkPatterns = [
        'title pending', 'untitled', 'no title', 'coming soon',
        '404', 'not found', 'page not found', 'access denied', 'forbidden',
        'just a moment', 'attention required', 'are you a robot', 'error',
    ];
    foreach ($junkPatterns as $pattern) {
        if (str_contains($normalized, $pattern)) return true;
    }
    return false;
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
    if ($title !== null && is_junk_title($title)) {
        $title = null; // caller treats a null title as "skip this page"
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
