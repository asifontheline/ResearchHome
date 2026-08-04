<?php
require_once __DIR__ . '/db.php';

/**
 * Per-IP request throttle — runs before any page does its own (potentially
 * expensive) queries, since functions.php is the first require in every
 * entry point. This protects the server itself, not just the traffic
 * numbers: a UA-spoofing scraper logged 135 requests in ~10 seconds on
 * 2026-07-31, each one a full page render (item listing, tag joins, etc.)
 * — on shared hosting that's real load, and enough of it can trip the
 * host's own resource limits and 508 the whole site for everyone, not just
 * the offender. record_page_view()'s 1/sec log-dedup only fixed the
 * *reported numbers*; this fixes the actual load.
 *
 * No APCu on this host (checked), so this is a small DB-backed fixed-
 * window counter — one cheap read+write per request, negligible next to
 * an actual page's own queries. Deliberately independent of the
 * daily-rotating analytics visitor_hash (that one's for anonymity; this
 * one just needs a stable per-IP key for the duration of one window).
 */
function enforce_request_rate_limit(int $maxRequests = 20, int $windowSeconds = 10): void {
    if (PHP_SAPI === 'cli') return; // never gate cron/CLI invocations (harvest.php, discover.php)
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($ip === '') return;

    $salt = defined('APP_SECRET') ? APP_SECRET : 'no-secret';
    $key = hash('sha256', $ip . $salt . 'throttle');

    try {
        $pdo = db();

        // No cron for this table, so it never gets cleaned up on its own —
        // 1-in-200 odds per request is cheap and keeps it bounded to
        // recently-active IPs without a dedicated maintenance job.
        if (random_int(1, 200) === 1) {
            $pdo->exec('DELETE FROM request_throttle WHERE window_start < DATE_SUB(NOW(), INTERVAL 1 HOUR)');
        }

        $stmt = $pdo->prepare('SELECT window_start, request_count FROM request_throttle WHERE throttle_key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();

        $windowExpired = !$row || strtotime($row['window_start']) <= time() - $windowSeconds;
        if ($windowExpired) {
            $pdo->prepare(
                'INSERT INTO request_throttle (throttle_key, window_start, request_count) VALUES (?, NOW(), 1)
                 ON DUPLICATE KEY UPDATE window_start = NOW(), request_count = 1'
            )->execute([$key]);
            return;
        }

        $newCount = (int)$row['request_count'] + 1;
        $pdo->prepare('UPDATE request_throttle SET request_count = ? WHERE throttle_key = ?')->execute([$newCount, $key]);

        if ($newCount > $maxRequests) {
            http_response_code(429);
            header('Retry-After: ' . $windowSeconds);
            header('Content-Type: text/plain; charset=UTF-8');
            echo "Too many requests — please slow down.";
            exit;
        }
    } catch (Throwable $e) {
        // A throttle-table hiccup must never take the whole site down —
        // fail open rather than blocking legitimate traffic on a DB blip.
        return;
    }
}
enforce_request_rate_limit();

const HARVEST_USER_AGENT_BASE = 'ResHubBot/1.0 (+personal research catalog; respects robots.txt)';
define('HARVEST_USER_AGENT', HARVEST_USER_AGENT_BASE . (defined('CONTACT_EMAIL') && CONTACT_EMAIL !== 'you@example.com' ? '; contact: ' . CONTACT_EMAIL : ''));

/**
 * IP -> country/region/city via ip-api.com's free tier (no key, HTTP only,
 * 45 req/min). Short timeout so a slow/down geo API can't stall a page
 * load by more than a beat — on any failure this just returns null and the
 * page view is still recorded without a location. The IP passed in is
 * never persisted by the caller; only the resolved names are stored.
 * Was 2s; bumped to 3s to catch more genuinely-slow-but-successful lookups
 * (this only runs once per visitor per day, via geo_cache, so the extra
 * second of worst-case latency is rare and cheap).
 */
function geolocate_ip(string $ip): ?array {
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return null; // private/loopback/reserved — nothing to look up (local dev, etc.)
    }
    $ch = curl_init("http://ip-api.com/json/{$ip}?fields=status,country,regionName,city,lat,lon");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 3,
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
    // Owner's own devices opt out via a persistent cookie (see notrack.php)
    // rather than IP allowlisting — mobile carrier IPs rotate constantly
    // and a laptop's IP changes across networks, so IP-based exclusion
    // wouldn't hold up in practice.
    if (!empty($_COOKIE['reshub_notrack'])) {
        return;
    }

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
        // UA-string bot filtering isn't airtight (confirmed: a scraper
        // logged 135 requests in ~10 seconds — physically impossible for a
        // human — using a UA that didn't match the bot regex above). This
        // catches that regardless of what UA is sent: no real visitor loads
        // pages faster than one per second, sustained.
        $recent = db()->prepare(
            'SELECT 1 FROM page_views WHERE visitor_hash = ? AND viewed_at >= DATE_SUB(NOW(), INTERVAL 1 SECOND) LIMIT 1'
        );
        $recent->execute([$visitorHash]);
        if ($recent->fetch()) {
            return;
        }

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
 * Every search-bar keyword submission, hit or miss — see search_log's
 * schema comment for how this differs from record_search_miss() above
 * (that one dedups/counts and exists to drive harvesting; this one is a
 * plain visibility log of what people actually search for).
 */
function record_search_log(string $q, int $resultCount): void {
    $q = trim(mb_strimwidth($q, 0, 255, ''));
    if ($q === '') return;
    try {
        db()->prepare('INSERT INTO search_log (query, result_count) VALUES (?, ?)')
            ->execute([$q, $resultCount]);
    } catch (Throwable $e) {
        // Never let this break the search page itself.
    }
}

/**
 * Builds MySQL BOOLEAN MODE AGAINST() candidates for a query, strictest
 * first, so index.php can try each in turn and use the first one that
 * actually finds something. A flat OR of every word let a single ordinary
 * word (e.g. "elements" in "periodic table elements") match a completely
 * unrelated document just because boolean mode's default is OR between
 * terms -- so this prefers the closest interpretation available instead
 * of jumping straight to the loosest one. But the loose OR tier is still
 * the last resort, not dropped: coming back with nothing is worse than a
 * loose-but-labeled-as-such match, since a genuine zero also queues a
 * harvest search behind the scenes -- the visitor shouldn't have to wait
 * for that when today's catalog already has *something* related.
 *
 *  1. The exact phrase (2+ words only) -- words together, in order.
 *  2. Every word required (each with a trailing wildcard, so a partial/
 *     prefix form still counts -- e.g. "chem" matches "chemistry").
 *  3. Any word at all (same wildcarding) -- the broadest tier, only used
 *     when neither stricter tier finds anything.
 *
 * Boolean-mode operator characters in the raw query (+-<>()~*"@) are
 * stripped from each word first, so user input can't form its own (or a
 * broken) boolean expression -- a query-syntax concern, not a security
 * one (every candidate is still passed as a bound parameter).
 */
function search_match_candidates(string $q): array {
    $words = array_values(array_filter(array_map(
        fn($w) => preg_replace('/[+\-<>()~*"@]+/', '', $w),
        preg_split('/\s+/', trim($q))
    ), fn($w) => $w !== ''));

    if (!$words) {
        return [];
    }

    $candidates = [];
    if (count($words) > 1) {
        $candidates[] = '"' . implode(' ', $words) . '"';
        $candidates[] = implode(' ', array_map(fn($w) => '+' . $w . '*', $words));
    }
    $candidates[] = implode(' ', array_map(fn($w) => $w . '*', $words));
    return $candidates;
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
 * Visit counts grouped by rounded lat/lon (2 decimals, ~1.1km -- was 1
 * decimal/~11km, widened per feedback that too many distinct visitors were
 * merging into too few dots) so exact-duplicate coordinates (the geo API
 * only gives city-level precision to begin with, so same-city visitors
 * often share identical lat/lon already) still collapse into one dot, but
 * nearby-yet-different cities no longer do.
 *
 * $window is 'all', 'today', or a specific 'YYYY-MM-DD' date -- validated
 * against a strict format so it's safe to interpolate directly (bound
 * params can't be used for a dynamic WHERE-vs-no-WHERE shape here without
 * two near-duplicate query strings; the regex check is the guard instead).
 */
function get_traffic_map_points(string $window): array {
    if ($window === 'today') {
        $since = 'WHERE pv.viewed_at >= CURDATE()';
    } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $window) === 1) {
        $day = db()->quote($window);
        $since = "WHERE pv.viewed_at >= {$day} AND pv.viewed_at < DATE_ADD({$day}, INTERVAL 1 DAY)";
    } else {
        $since = '';
    }
    return db()->query(
        "SELECT ROUND(g.lat, 2) AS lat, ROUND(g.lon, 2) AS lon,
                MAX(g.city) AS city, MAX(g.country) AS country,
                COUNT(*) AS views, COUNT(DISTINCT pv.visitor_hash) AS unique_visitors
         FROM page_views pv
         JOIN geo_cache g ON g.visitor_hash = pv.visitor_hash
         {$since}
         " . ($since !== '' ? 'AND' : 'WHERE') . " g.lat IS NOT NULL AND g.lon IS NOT NULL
         GROUP BY ROUND(g.lat, 2), ROUND(g.lon, 2)
         ORDER BY views DESC"
    )->fetchAll();
}

/** Every distinct calendar day (UTC) that has at least one page view, newest first -- for the map's day picker. */
function get_traffic_days(): array {
    return array_column(
        db()->query('SELECT DISTINCT DATE(viewed_at) AS d FROM page_views ORDER BY d DESC')->fetchAll(),
        'd'
    );
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
        // Log scaling -- was sqrt, still let one location's dot visually
        // dominate the map when its view count was far above everywhere
        // else (a 10-100x gap only shrinks to ~3-10x under sqrt). Log
        // compresses that gap much further while still keeping every dot
        // strictly ordered by view count.
        $r = 2.5 + 6 * log($p['views'] + 1) / log($maxViews + 1);
        $label = trim(($p['city'] ?? '') . ', ' . ($p['country'] ?? ''), ', ');
        $tip = sprintf('%s: %d view%s', $label ?: 'Unknown', (int)$p['views'], (int)$p['views'] === 1 ? '' : 's');
        // data-tip drives a custom JS tooltip below — native SVG <title>
        // tooltips are unreliable across browsers (delayed, sometimes don't
        // fire at all through a CSS transform like the zoom control uses).
        $dots .= sprintf(
            '<circle cx="%.2f" cy="%.2f" r="%.2f" class="geo-dot" data-tip="%s"></circle>',
            $x, $y, $r, h($tip)
        );
    }
    // Zoom via CSS transform: scale on a fixed-px-width inner wrap, inside a
    // scrolling viewport — no map/JS library. Panning is drag-to-scroll
    // (Pointer Events move viewport.scrollLeft/Top directly) since relying
    // on the native scrollbars alone made the map feel stuck growing only
    // toward the top-left corner as it zoomed.
    return sprintf(
        '<div class="map-toolbar" data-map="%1$s">
            <button type="button" class="map-zoom-btn" data-zoom="-1" aria-label="Zoom out">&minus;</button>
            <span class="map-zoom-level" id="%1$s-level">100%%</span>
            <button type="button" class="map-zoom-btn" data-zoom="1" aria-label="Zoom in">+</button>
            <button type="button" class="map-zoom-reset">Reset</button>
            <span class="map-hint muted">Drag to pan</span>
        </div>
        <div class="world-map-viewport" id="%1$s-viewport">
            <div class="world-map-wrap" id="%1$s-wrap">
                <img src="/assets/world-map.svg?v=%4$d" class="world-map-bg" alt="World map" width="784" height="459" loading="lazy" draggable="false">
                <svg class="world-map-dots" id="%1$s-dots" viewBox="30.767 241.591 784.077 458.627" preserveAspectRatio="xMidYMid meet" aria-hidden="true">%2$s</svg>
            </div>
            <div class="map-tooltip" id="%1$s-tooltip"></div>
        </div>
        <p class="muted map-credit">Map: Al MacDonald / Fritz Lekschas, CC BY-SA 3.0. Dot size ~ views, city-level precision.</p>
        <script>
        (function () {
            var id = %3$s;
            var wrap = document.getElementById(id + "-wrap");
            var viewport = document.getElementById(id + "-viewport");
            var level = document.getElementById(id + "-level");
            var dotsSvg = document.getElementById(id + "-dots");
            var tooltip = document.getElementById(id + "-tooltip");
            var toolbar = document.querySelector(\'.map-toolbar[data-map="\' + id + \'"]\');
            var zoom = 1;

            function apply() {
                wrap.style.transform = "scale(" + zoom + ")";
                level.textContent = Math.round(zoom * 100) + "%%";
            }
            function center() {
                viewport.scrollLeft = (wrap.offsetWidth * zoom - viewport.clientWidth) / 2;
                viewport.scrollTop = (wrap.offsetHeight * zoom - viewport.clientHeight) / 2;
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
                center();
            });

            // Custom tooltip — native SVG <title> is unreliable across
            // browsers (delayed, sometimes silently doesn\'t fire, especially
            // through the CSS transform: scale() the zoom control applies).
            // Positioned relative to the viewport (not wrap) so it doesn\'t
            // get scaled along with the map at higher zoom levels.
            function positionTip(e) {
                var rect = viewport.getBoundingClientRect();
                tooltip.style.left = (e.clientX - rect.left + viewport.scrollLeft + 14) + "px";
                tooltip.style.top = (e.clientY - rect.top + viewport.scrollTop + 10) + "px";
            }
            dotsSvg.addEventListener("pointerover", function (e) {
                if (!e.target.classList || !e.target.classList.contains("geo-dot")) return;
                tooltip.textContent = e.target.getAttribute("data-tip") || "";
                tooltip.classList.add("visible");
                positionTip(e);
            });
            dotsSvg.addEventListener("pointermove", function (e) {
                if (!e.target.classList || !e.target.classList.contains("geo-dot")) return;
                positionTip(e);
            });
            dotsSvg.addEventListener("pointerout", function (e) {
                if (!e.target.classList || !e.target.classList.contains("geo-dot")) return;
                tooltip.classList.remove("visible");
            });

            // Drag-to-pan in any direction (mouse + touch via Pointer Events).
            var dragging = false, startX = 0, startY = 0, startLeft = 0, startTop = 0;
            viewport.addEventListener("pointerdown", function (e) {
                dragging = true;
                startX = e.clientX; startY = e.clientY;
                startLeft = viewport.scrollLeft; startTop = viewport.scrollTop;
                viewport.classList.add("dragging");
                viewport.setPointerCapture(e.pointerId);
                tooltip.classList.remove("visible");
            });
            viewport.addEventListener("pointermove", function (e) {
                if (!dragging) return;
                viewport.scrollLeft = startLeft - (e.clientX - startX);
                viewport.scrollTop = startTop - (e.clientY - startY);
            });
            function endDrag() {
                dragging = false;
                viewport.classList.remove("dragging");
            }
            viewport.addEventListener("pointerup", endDrag);
            viewport.addEventListener("pointercancel", endDrag);

            apply();
            center();
        })();
        </script>',
        h($domId), $dots, json_encode($domId), filemtime(__DIR__ . '/../assets/world-map.svg')
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
/**
 * No publisher-homepage URL is ever harvested or stored — source_name is a
 * free-text string from the API (Crossref's publisher field, OpenAlex's
 * host org, etc.), not a URL. Linking to a specific harvested item's deep
 * URL was tried and rejected — those rot (a repository reorganizes, an
 * item page moves) even when the site itself is fine. Deriving just the
 * site root (scheme://host/) from a real harvested URL is far more
 * durable: homepages essentially never go dead the way a specific deep
 * link can. For DOI-resolved sources this yields https://doi.org/ (all of
 * them share that host) — less specific, but truthful and never broken,
 * rather than guessing an individual publisher's actual domain.
 */
function all_contributing_sources(): array {
    $rows = db()->query(
        "SELECT i.source_name, COUNT(*) AS item_count,
                SUBSTRING_INDEX(GROUP_CONCAT(i.url ORDER BY i.added_at DESC), ',', 1) AS sample_url
         FROM items i
         WHERE i.source_name IS NOT NULL AND i.content_type = 'research'
         GROUP BY i.source_name
         ORDER BY item_count DESC, i.source_name ASC"
    )->fetchAll();

    foreach ($rows as &$row) {
        $parts = $row['sample_url'] ? parse_url($row['sample_url']) : null;
        $row['homepage_url'] = ($parts && !empty($parts['scheme']) && !empty($parts['host']))
            ? "{$parts['scheme']}://{$parts['host']}/"
            : null;
    }
    return $rows;
}

/**
 * Sites the crawler gave up on for good (see SEED_PERMANENT_DISABLE_CYCLES
 * in harvester.php — 7 consecutive block cycles with zero successes
 * between them). Shown publicly on Credits rather than just silently
 * dropped: the crawler can't get in, but a human still can, so the link
 * stays available for direct searching.
 */
function permanently_blocked_seeds(): array {
    return db()->query(
        "SELECT url, host, block_cycles FROM seed_urls
         WHERE permanently_disabled = 1
         ORDER BY host ASC"
    )->fetchAll();
}

/** Every existing tag name, for datalist autocomplete on free-text tag inputs (add.php, edit.php). */
function all_tag_names(): array {
    return array_column(db()->query('SELECT name FROM tags ORDER BY name ASC')->fetchAll(), 'name');
}

function all_tags_with_counts(string $contentType = 'research'): array {
    $stmt = db()->prepare(
        "SELECT t.id, t.name, t.slug, COUNT(i.id) AS item_count
         FROM tags t
         LEFT JOIN item_tags it ON it.tag_id = t.id
         LEFT JOIN items i ON i.id = it.item_id AND i.content_type = ?
         GROUP BY t.id
         ORDER BY t.name"
    );
    $stmt->execute([$contentType]);
    return $stmt->fetchAll();
}

function get_catalog_summary(): array {
    return [
        'total_items' => (int) db()->query("SELECT COUNT(*) FROM items WHERE content_type = 'research'")->fetchColumn(),
        'total_tags' => (int) db()->query('SELECT COUNT(*) FROM tags')->fetchColumn(),
        'total_sources' => (int) db()->query("SELECT COUNT(DISTINCT source_name) FROM items WHERE source_name IS NOT NULL AND content_type = 'research'")->fetchColumn(),
        'last_run' => db()->query('SELECT * FROM harvest_log ORDER BY started_at DESC LIMIT 1')->fetch() ?: null,
    ];
}

/**
 * Video harvesting (YouTube/Vimeo) is opt-in via config keys that may not
 * be set — until at least one video has actually been harvested, "By
 * Video" links to an empty page. Checked instead of just "is a key
 * configured" so the link only appears once there's real content, not the
 * moment a key is added but before the next harvest run has found anything.
 */
function has_video_content(): bool {
    return (bool) db()->query("SELECT 1 FROM items WHERE content_type = 'video' LIMIT 1")->fetchColumn();
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
/**
 * Self-migrating, same pattern as ensure_subjects_table() -- adds
 * items.language (ISO 639, 2 or 3 letters depending on what the source
 * gives us -- <html lang> attributes and Crossref/OpenAlex are 2-letter,
 * PubMed's esummary is 3-letter, and there's no reason to force a
 * normalization table just to display a small badge) if it isn't there
 * yet. Guarded by a settings flag so the INFORMATION_SCHEMA check only
 * runs once, not on every insert.
 */
function ensure_items_language_column(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    if (get_setting('items_language_migrated', '') === '1') {
        return;
    }

    $exists = (int) db()->query(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'items' AND column_name = 'language'"
    )->fetchColumn();
    if ($exists === 0) {
        db()->exec('ALTER TABLE items ADD COLUMN language VARCHAR(8) DEFAULT NULL');
    }
    set_setting('items_language_migrated', '1');
}

function insert_item_if_new(array $fields, array $tagNames = []): ?int {
    if (is_junk_title($fields['title'] ?? '')) {
        return null;
    }

    ensure_items_language_column();

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
        'INSERT INTO items (title, url, url_hash, authors, abstract, notes, source_name, published_date, image_url, citation_count, content_type, language)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
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
        $fields['content_type'] ?? 'research',
        $fields['language'] ?? null,
    ]);
    $itemId = (int) $pdo->lastInsertId();
    if ($itemId <= 0) {
        throw new RuntimeException('insert_item_if_new: lastInsertId() returned 0 after a successful insert — refusing to write item_tags against an invalid id.');
    }

    // 'general' (subjects.php) whenever nothing else applied -- no source
    // category, no seed subject, no classify_subjects() keyword match.
    // Guarantees no item is ever left with zero tags, regardless of which
    // harvest path added it.
    set_item_tags($itemId, resolve_tag_ids(implode(',', $tagNames ?: ['general'])));

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
/**
 * Creates the `subjects` table and seeds it from includes/subjects.php on
 * first use -- once per deploy, not once per request (guarded by a
 * 'subjects_migrated' setting, checked before touching the DB at all).
 *
 * Why a DB table instead of just editing the static file: this app's
 * deploy replaces every tracked file on every `git push` (see
 * .github/workflows/deploy.yml), the same reason config.php is excluded
 * from deploy rather than editable at runtime. A subject an admin adds via
 * subjects_admin.php would get silently wiped by the next deploy if it
 * only lived in a file. DB storage survives deploys the same way items/
 * tags/seeds already do.
 */
function ensure_subjects_table(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    if (get_setting('subjects_migrated', '') === '1') {
        return;
    }

    db()->exec(
        'CREATE TABLE IF NOT EXISTS subjects (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(64) NOT NULL UNIQUE,
            label VARCHAR(128) NOT NULL,
            parent VARCHAR(128) NOT NULL,
            keywords TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $count = (int) db()->query('SELECT COUNT(*) FROM subjects')->fetchColumn();
    if ($count === 0) {
        $seed = require __DIR__ . '/subjects.php';
        $stmt = db()->prepare('INSERT INTO subjects (slug, label, parent, keywords) VALUES (?, ?, ?, ?)');
        foreach ($seed as $slug => $def) {
            $stmt->execute([$slug, $def['label'], $def['parent'], implode(',', $def['keywords'])]);
        }
    }

    set_setting('subjects_migrated', '1');
}

/**
 * Live subject taxonomy from the DB, same shape the old static
 * includes/subjects.php array had (['slug' => ['label'=>, 'parent'=>,
 * 'keywords'=>[...]]]) so every existing caller (classify_subjects(),
 * get_grouped_subjects(), subject_label(), the seed/search/add forms)
 * works unchanged. Cached per-request via the static var -- this is
 * called from inside tight loops (classify_subjects() runs it, and that
 * runs per item during a harvest batch), a fresh DB round-trip every call
 * would be wasteful.
 */
function get_subjects(): array {
    static $subjects = null;
    if ($subjects !== null) {
        return $subjects;
    }

    ensure_subjects_table();

    $subjects = [];
    $rows = db()->query('SELECT slug, label, parent, keywords FROM subjects ORDER BY id ASC')->fetchAll();
    foreach ($rows as $row) {
        $subjects[$row['slug']] = [
            'label' => $row['label'],
            'parent' => $row['parent'],
            'keywords' => array_values(array_filter(array_map('trim', explode(',', $row['keywords'])))),
        ];
    }
    return $subjects;
}

function classify_subjects(string $text): array {
    $subjects = get_subjects();
    $text = mb_strtolower($text);
    $matches = [];
    foreach ($subjects as $slug => $def) {
        foreach ($def['keywords'] as $kw) {
            $kw = mb_strtolower($kw);
            // Word-boundary match, not a raw substring search — plain
            // mb_strpos let single-word keywords fire inside unrelated
            // words/phrases (this is how a gut-microbiota/anemia review got
            // tagged "Law": "regulation" as a bare substring keyword matched
            // "iron regulation" in the abstract).
            if (preg_match('/\b' . preg_quote($kw, '/') . '\b/u', $text) === 1) {
                $matches[] = $slug;
                break;
            }
        }
    }
    return $matches;
}

function subject_label(?string $slug): string {
    if ($slug === null) return 'General';
    return get_subjects()[$slug]['label'] ?? $slug;
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

function get_grouped_subjects(string $contentType = 'research'): array {
    $subjects = get_subjects();
    $tagCounts = all_tags_with_counts($contentType);
    $counts = [];
    foreach ($tagCounts as $t) {
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
    foreach ($tagCounts as $t) {
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
           AND content_type = 'research'
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
        'language' => 'en', // arXiv doesn't report one; near-universally English in practice
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
        'language' => isset($doc['lang'][0]) ? strtolower($doc['lang'][0]) : null, // 3-letter (ISO 639-2/B), e.g. "eng", "ger"
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
        'language' => isset($msg['language']) ? strtolower($msg['language']) : null,
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

/**
 * Catches a different failure mode than is_junk_title(): not a broken/empty
 * title, but a real, well-formed one that's just the SITE's own name, not a
 * specific work -- a category/hub/index page's <title> often is exactly
 * this (confirmed on production: an old PLOS domain's journal-listing pages
 * each had a plain, short title like "PLOS One", "PLOS Genetics" -- no
 * article behind them at all, just the site's own section headers -- and
 * the crawler inserted ~40 of them as fake catalog items since a
 * short/generic title still isn't "junk" by is_junk_title()'s rules).
 *
 * Two independent signals, either one enough on its own:
 *  1. A short title (<=3 words) that's essentially the host name with the
 *     punctuation stripped out, either direction -- a real article title
 *     that happens to be that short and that close to the domain name
 *     would be a very unusual coincidence.
 *  2. A short title that's one of a small set of generic navigational
 *     words ("Home", "Welcome", "Journal Home", ...) -- these say nothing
 *     about the host at all, so signal 1 alone wouldn't catch them, but
 *     they're just as clearly not an article headline.
 * Confirmed on production: an old PLOS domain's journal-listing pages had
 * titles like "PLOS One" and "PLOS Genetics" (signal 1 -- other PLOS
 * journal names on that same plosone.org host wouldn't textually match it,
 * which is why this isn't host-matching alone) -- no article behind any of
 * them, just the site's own section headers, and ~40 got inserted as fake
 * catalog items since a short/generic title still isn't "junk" by
 * is_junk_title()'s rules.
 */
function looks_like_site_branding(string $title, string $host): bool {
    if (str_word_count($title) > 3) return false;

    $normalized = trim(mb_strtolower($title));
    $genericHubTitles = ['home', 'homepage', 'welcome', 'journal home', 'main page', 'landing page', 'index'];
    if (in_array($normalized, $genericHubTitles, true)) return true;

    $normalizedTitle = preg_replace('/[^a-z0-9]/', '', $normalized);
    $normalizedHost = preg_replace('/[^a-z0-9]/', '', mb_strtolower(preg_replace('/^www\./', '', $host)));
    if ($normalizedTitle === '' || $normalizedHost === '') return false;
    return str_contains($normalizedHost, $normalizedTitle) || str_contains($normalizedTitle, $normalizedHost);
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

    // <html lang="..."> is standard, near-universal markup and far cheaper
    // and more reliable than statistical language detection -- falls back
    // to og:locale (e.g. "de_DE") when a page skips the lang attribute.
    $language = null;
    if (preg_match('#<html[^>]+lang=["\']([a-zA-Z]{2,3})#i', $body, $m)) {
        $language = strtolower($m[1]);
    } elseif (($locale = $get_meta('og:locale')) && preg_match('/^([a-zA-Z]{2,3})/', $locale, $m)) {
        $language = strtolower($m[1]);
    }

    return [
        'title' => $title,
        'authors' => $get_meta('author') ?? $get_meta('citation_author'),
        'abstract' => $get_meta('og:description') ?? $get_meta('description'),
        'published_date' => ($d = $get_meta('article:published_time') ?? $get_meta('citation_publication_date')) ? date('Y-m-d', strtotime($d)) : null,
        'source_name' => $sourceName,
        'image_url' => $get_meta('og:image'),
        'language' => $language,
    ];
}

/**
 * Plain-text snippet of a page's visible body, for classify_subjects() to
 * search when there's no og:description/description meta tag to work with
 * (common on generic crawled pages) -- not stored anywhere, just fed into
 * the classifier as extra text to match keywords against.
 */
function extract_body_text(string $html, int $maxChars = 4000): string {
    $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return mb_substr(trim($text), 0, $maxChars);
}
