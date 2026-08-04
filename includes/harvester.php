<?php
require_once __DIR__ . '/functions.php';

// ---- Run locking + soft time budget --------------------------------------
//
// Two separate safeguards against overlapping/runaway cron invocations:
//
// 1. A lock (settings table) so a new harvest.php/discover.php invocation
//    refuses to start if a previous one is still within its max plausible
//    runtime — cron firing again before the last run finished (a slow batch
//    of external API calls, a big backlog of seeds) would otherwise spawn a
//    second instance racing the first over the same DB rows. The lock has
//    a max age shorter than the cron interval itself, so a genuinely
//    crashed/killed run (which can't release its own lock) self-heals on
//    the next tick rather than deadlocking forever.
// 2. A soft time budget checked inside each loop, *between* items — not a
//    hard kill. When the budget is exceeded, the current item still
//    finishes cleanly and the loop simply stops starting new ones. Whatever
//    wasn't reached (remaining seeds, queue rows, API sources) is untouched
//    — crawl_queue/seed_urls/settings are all already designed to persist
//    that as normal pending work, so the next cron cycle just continues
//    where this one left off instead of anything being lost.

function acquire_run_lock(string $lockName, int $maxMinutes): bool {
    $key = "{$lockName}_lock_started_at";
    $existing = get_setting($key);
    if ($existing && (time() - strtotime($existing)) < $maxMinutes * 60) {
        return false; // still within another run's plausible runtime — don't overlap
    }
    set_setting($key, date('Y-m-d H:i:s'));
    return true;
}

function release_run_lock(string $lockName): void {
    set_setting("{$lockName}_lock_started_at", '');
}

function time_budget_exceeded(float $deadline): bool {
    return microtime(true) > $deadline;
}

// ---- Politeness: robots.txt + per-host rate limiting ---------------------

/**
 * Read-only, never triggers a live robots.txt fetch — unlike
 * get_or_fetch_host(). Used where a miss should mean "we don't know yet,
 * check later" rather than "block here and now": a hub/directory-style
 * seed page can link to hundreds of never-before-seen hosts in one go, and
 * synchronously fetching robots.txt (up to 15s x2 for the https/http
 * fallback) for every single one of them before any can be queued turned
 * a normal ~4-minute harvest run into 13+ minutes and counting — confirmed
 * on production (291 brand-new hosts robots-fetched in a single run).
 */
function get_cached_host(string $host): ?array {
    $stmt = db()->prepare('SELECT * FROM hosts WHERE host = ?');
    $stmt->execute([$host]);
    return $stmt->fetch() ?: null;
}

function get_or_fetch_host(string $host): array {
    $stmt = db()->prepare('SELECT * FROM hosts WHERE host = ?');
    $stmt->execute([$host]);
    $row = $stmt->fetch();

    $stale = !$row || !$row['robots_fetched_at'] || strtotime($row['robots_fetched_at']) < strtotime('-24 hours');
    if ($stale) {
        [$rules, $disallowedAll, $crawlDelay] = fetch_robots_rules($host);
        db()->prepare(
            'INSERT INTO hosts (host, robots_rules, robots_fetched_at, crawl_delay_seconds, disallowed)
             VALUES (?, ?, NOW(), ?, ?)
             ON DUPLICATE KEY UPDATE robots_rules = VALUES(robots_rules),
                 robots_fetched_at = NOW(), crawl_delay_seconds = VALUES(crawl_delay_seconds),
                 disallowed = VALUES(disallowed)'
        )->execute([$host, implode("\n", $rules), $crawlDelay, $disallowedAll ? 1 : 0]);

        $stmt->execute([$host]);
        $row = $stmt->fetch();
    }
    return $row;
}

function fetch_robots_rules(string $host): array {
    $body = safe_http_get("https://{$host}/robots.txt", ["User-Agent: " . HARVEST_USER_AGENT]);
    if ($body === null) {
        $body = safe_http_get("http://{$host}/robots.txt", ["User-Agent: " . HARVEST_USER_AGENT]);
    }
    if ($body === null) {
        return [[], false, 5]; // no robots.txt found: assume allowed, be conservative on delay
    }

    $lines = preg_split('/\r\n|\r|\n/', $body);
    $rules = [];
    $crawlDelay = 5;
    $inWildcardBlock = false;
    $anyBlockMatched = false;

    foreach ($lines as $line) {
        $line = trim(preg_replace('/#.*$/', '', $line));
        if ($line === '') continue;
        if (!str_contains($line, ':')) continue;
        [$key, $value] = array_map('trim', explode(':', $line, 2));
        $key = strtolower($key);

        if ($key === 'user-agent') {
            $inWildcardBlock = ($value === '*');
            continue;
        }
        if ($inWildcardBlock && $key === 'disallow' && $value !== '') {
            $rules[] = $value;
            $anyBlockMatched = true;
        }
        if ($inWildcardBlock && $key === 'crawl-delay') {
            $crawlDelay = max($crawlDelay, (int)$value);
        }
    }

    $disallowedAll = in_array('/', $rules, true);
    return [$rules, $disallowedAll, $crawlDelay];
}

function robots_path_allowed(array $hostRow, string $path): bool {
    if ((int)$hostRow['disallowed'] === 1) return false;
    $rules = array_filter(explode("\n", $hostRow['robots_rules'] ?? ''));
    foreach ($rules as $rule) {
        if ($rule !== '' && str_starts_with($path, $rule)) {
            return false;
        }
    }
    return true;
}

function host_ready_to_crawl(array $hostRow): bool {
    if (!$hostRow['last_crawled_at']) return true;
    $delay = (int)$hostRow['crawl_delay_seconds'];
    return strtotime($hostRow['last_crawled_at']) <= time() - $delay;
}

function mark_host_crawled(string $host): void {
    db()->prepare('UPDATE hosts SET last_crawled_at = NOW() WHERE host = ?')->execute([$host]);
}

/**
 * Full politeness check for a URL: robots.txt allowed + host not rate-limited.
 */
function can_crawl_url(string $url): bool {
    $parts = parse_url($url);
    if (!$parts || empty($parts['host'])) return false;
    $host = $parts['host'];
    $path = $parts['path'] ?? '/';

    $hostRow = get_or_fetch_host($host);
    if (!robots_path_allowed($hostRow, $path)) return false;
    if (!host_ready_to_crawl($hostRow)) return false;

    return true;
}

// ---- Link extraction -------------------------------------------------

// Path fragments that mark a link as site chrome/navigation rather than content.
const NAV_LINK_PATTERN = '#/(login|signin|signup|logout|register|donate|about|contact|privacy|terms|subscribe|cookies?)(/|$|\?)#i';

function extract_links(string $html, string $baseUrl): array {
    if (!preg_match_all('/<a\s[^>]*href=["\']([^"\'#]+)["\'][^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER)) {
        return [];
    }
    $base = parse_url($baseUrl);
    $basePath = rtrim($base['path'] ?? '/', '/');
    $links = [];
    $seen = [];
    foreach ($matches as $m) {
        $href = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
        $text = trim(strip_tags($m[2]));
        if ($href === '' || str_starts_with($href, 'javascript:') || str_starts_with($href, 'mailto:')) {
            continue;
        }
        $resolved = resolve_url($href, $base);
        if (!$resolved || isset($seen[$resolved])) continue;
        if (is_nav_link($resolved, $basePath)) continue;
        $seen[$resolved] = true;
        $links[] = ['url' => $resolved, 'text' => $text];
    }
    return $links;
}

function is_nav_link(string $url, string $basePath): bool {
    $parts = parse_url($url);
    $path = rtrim($parts['path'] ?? '/', '/');

    if ($path === '' || $path === '/') return true;           // homepage
    if ($path === $basePath) return true;                     // pagination of the same hub page
    if (preg_match(NAV_LINK_PATTERN, $path)) return true;      // login/donate/about/etc.

    return false;
}

function resolve_url(string $href, array $base): ?string {
    if (preg_match('#^https?://#i', $href)) {
        return $href;
    }
    if (str_starts_with($href, '//')) {
        return ($base['scheme'] ?? 'https') . ':' . $href;
    }
    if (empty($base['scheme']) || empty($base['host'])) {
        return null;
    }
    $origin = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');
    if (str_starts_with($href, '/')) {
        return $origin . $href;
    }
    $basePath = $base['path'] ?? '/';
    $dir = substr($basePath, 0, strrpos($basePath, '/') + 1);
    return $origin . $dir . $href;
}

// ---- API harvest: arXiv -------------------------------------------------

function api_harvest_arxiv(string $subjectSlug, string $keyword, int $max = 8): array {
    $query = 'all:' . str_replace(' ', '+', $keyword);
    $body = safe_http_get("http://export.arxiv.org/api/query?search_query={$query}&sortBy=submittedDate&sortOrder=descending&max_results={$max}");
    if (!$body) return ['added' => 0, 'error' => 'arXiv request failed'];

    $xml = @simplexml_load_string($body);
    if (!$xml || !isset($xml->entry)) return ['added' => 0];

    $added = 0;
    foreach ($xml->entry as $entry) {
        $authors = [];
        foreach ($entry->author as $a) $authors[] = (string)$a->name;
        $url = trim((string)$entry->id);
        $title = trim((string)$entry->title);
        $abstract = trim((string)$entry->summary);

        // arXiv's own declared category codes (e.g. cs.LG, astro-ph.CO) are a
        // richer, self-reported taxonomy than our seed keyword list — use them
        // directly instead of forcing every item into one of our subject slugs.
        // Expanded to a plain-English label (see arxiv_category_label()) so a
        // reader unfamiliar with arXiv's codes isn't left staring at "cs.LG".
        $arxivCategories = [];
        foreach ($entry->category as $cat) {
            $term = (string) $cat['term'];
            if ($term !== '') $arxivCategories[] = arxiv_category_label($term);
        }

        $tags = array_unique(array_merge($arxivCategories, classify_subjects($title . ' ' . $abstract), array_filter([$subjectSlug])));
        $id = insert_item_if_new([
            'title' => $title, 'url' => $url,
            'authors' => implode(', ', $authors), 'abstract' => $abstract,
            'source_name' => 'arXiv',
            'published_date' => date('Y-m-d', strtotime((string)$entry->published)),
            'language' => 'en', // arXiv doesn't report one; near-universally English in practice
        ], $tags);
        if ($id) $added++;
    }
    return ['added' => $added];
}

// ---- API harvest: Crossref ------------------------------------------------

function api_harvest_crossref(string $subjectSlug, string $keyword, int $max = 8): array {
    $q = urlencode($keyword);
    // mailto= opts us into Crossref's "polite pool" — identified callers get
    // more consistent service than anonymous ones. See CONTACT_EMAIL in config.php.
    $contact = defined('CONTACT_EMAIL') ? '&mailto=' . urlencode(CONTACT_EMAIL) : '';
    $body = safe_http_get("https://api.crossref.org/works?query={$q}&rows={$max}&sort=published&order=desc&filter=type:journal-article{$contact}");
    if (!$body) return ['added' => 0, 'error' => 'Crossref request failed'];

    $data = json_decode($body, true);
    $items = $data['message']['items'] ?? [];
    $added = 0;
    foreach ($items as $msg) {
        $url = $msg['URL'] ?? null;
        $title = $msg['title'][0] ?? null;
        if (!$url || !$title) continue;

        $authors = array_map(function ($a) {
            return trim(($a['given'] ?? '') . ' ' . ($a['family'] ?? ''));
        }, $msg['author'] ?? []);
        $abstract = isset($msg['abstract']) ? strip_tags($msg['abstract']) : '';
        $dateParts = $msg['published']['date-parts'][0] ?? null;
        $published = $dateParts ? implode('-', array_pad($dateParts, 3, '01')) : null;

        // Crossref returns its own publisher-supplied subject-area strings on
        // many records — use them alongside our keyword classification rather
        // than only the one subject slug that produced this search.
        $crossrefSubjects = $msg['subject'] ?? [];
        $tags = array_unique(array_merge($crossrefSubjects, classify_subjects($title . ' ' . $abstract), array_filter([$subjectSlug])));

        $id = insert_item_if_new([
            'title' => $title, 'url' => $url,
            'authors' => implode(', ', array_filter($authors)),
            'abstract' => $abstract ?: null,
            'source_name' => $msg['publisher'] ?? 'Crossref',
            'published_date' => $published,
            'citation_count' => $msg['is-referenced-by-count'] ?? null,
            'language' => isset($msg['language']) ? strtolower($msg['language']) : null,
        ], $tags);
        if ($id) $added++;
    }
    return ['added' => $added];
}

// ---- API harvest: PubMed ------------------------------------------------

function api_harvest_pubmed(string $subjectSlug, string $keyword, int $max = 8): array {
    $key = defined('NCBI_API_KEY') && NCBI_API_KEY ? '&api_key=' . urlencode(NCBI_API_KEY) : '';
    // NCBI's E-utilities usage policy asks callers to identify themselves via
    // tool= and email= — not required to get a response, but documented policy.
    $contact = defined('CONTACT_EMAIL') ? '&tool=researchhome&email=' . urlencode(CONTACT_EMAIL) : '';
    $q = urlencode($keyword);
    $searchBody = safe_http_get("https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi?db=pubmed&term={$q}&retmax={$max}&sort=date&retmode=json{$key}{$contact}");
    if (!$searchBody) return ['added' => 0, 'error' => 'PubMed search failed'];

    $ids = json_decode($searchBody, true)['esearchresult']['idlist'] ?? [];
    if (!$ids) return ['added' => 0];

    // NCBI caps unauthenticated callers at 3 req/sec (10/sec with an API
    // key) — each fetch_pubmed() below is its own esummary request against
    // the same host as the esearch call above, so a tight loop without this
    // could exceed that within a single harvest run.
    $minIntervalMicroseconds = (defined('NCBI_API_KEY') && NCBI_API_KEY) ? 110000 : 350000;

    $added = 0;
    foreach ($ids as $pmid) {
        usleep($minIntervalMicroseconds);
        $meta = fetch_pubmed($pmid);
        if (!$meta || !$meta['title']) continue;
        $url = "https://pubmed.ncbi.nlm.nih.gov/{$pmid}/";
        $tags = array_unique(array_merge(classify_subjects($meta['title']), array_filter([$subjectSlug])));
        $id = insert_item_if_new([
            'title' => $meta['title'], 'url' => $url,
            'authors' => $meta['authors'], 'abstract' => $meta['abstract'],
            'source_name' => 'PubMed', 'published_date' => $meta['published_date'],
            'language' => $meta['language'] ?? null,
        ], $tags);
        if ($id) $added++;
    }
    return ['added' => $added];
}

// ---- API harvest: OpenAlex ------------------------------------------------
// Free, no key required. ~250M works across every field — by far the
// broadest single free source available, well beyond Crossref's journal-
// article-only coverage (books, datasets, preprints, more disciplines).

function reconstruct_openalex_abstract(?array $invertedIndex): ?string {
    if (!$invertedIndex) return null;
    $words = [];
    foreach ($invertedIndex as $word => $positions) {
        foreach ($positions as $pos) {
            $words[$pos] = $word;
        }
    }
    if (!$words) return null;
    ksort($words);
    return implode(' ', $words);
}

function api_harvest_openalex(string $subjectSlug, string $keyword, int $max = 8): array {
    $q = urlencode($keyword);
    // mailto= puts us in OpenAlex's "polite pool" (higher, steadier rate limits) — no account needed.
    $body = safe_http_get("https://api.openalex.org/works?search={$q}&per-page={$max}&sort=publication_date:desc&mailto=researchhome@example.com");
    if (!$body) return ['added' => 0, 'error' => 'OpenAlex request failed'];

    $data = json_decode($body, true);
    $works = $data['results'] ?? [];
    $added = 0;
    foreach ($works as $work) {
        $title = $work['title'] ?? null;
        $url = $work['open_access']['oa_url']
            ?? $work['primary_location']['landing_page_url']
            ?? $work['id'] ?? null;
        if (!$title || !$url) continue;

        $authors = array_map(fn($a) => $a['author']['display_name'] ?? '', $work['authorships'] ?? []);
        $abstract = reconstruct_openalex_abstract($work['abstract_inverted_index'] ?? null);
        $topics = array_map(fn($t) => $t['display_name'] ?? '', $work['topics'] ?? []);

        $tags = array_unique(array_merge(array_filter($topics), classify_subjects($title . ' ' . ($abstract ?? '')), array_filter([$subjectSlug])));

        $id = insert_item_if_new([
            'title' => $title, 'url' => $url,
            'authors' => implode(', ', array_filter($authors)),
            'abstract' => $abstract,
            'source_name' => $work['primary_location']['source']['display_name'] ?? 'OpenAlex',
            'published_date' => $work['publication_date'] ?? null,
            'citation_count' => $work['cited_by_count'] ?? null,
            'language' => isset($work['language']) ? strtolower($work['language']) : null,
        ], $tags);
        if ($id) $added++;
    }
    return ['added' => $added];
}

// ---- API harvest: Semantic Scholar ----------------------------------------
// Works without a key, but their unauthenticated pool is shared globally
// across every unauthenticated caller and gets exhausted (429) easily —
// a free key (no cost, self-serve) raises the limit substantially. Same
// optional-key pattern as NCBI_API_KEY / PATENTSVIEW_API_KEY: works either
// way, just less reliably without one.

function api_harvest_semanticscholar(string $subjectSlug, string $keyword, int $max = 8): array {
    $q = urlencode($keyword);
    $fields = 'title,abstract,authors,year,externalIds,openAccessPdf,url,fieldsOfStudy,citationCount';
    $headers = (defined('SEMANTIC_SCHOLAR_API_KEY') && SEMANTIC_SCHOLAR_API_KEY)
        ? ['x-api-key: ' . SEMANTIC_SCHOLAR_API_KEY] : [];
    $body = safe_http_get("https://api.semanticscholar.org/graph/v1/paper/search?query={$q}&limit={$max}&fields={$fields}", $headers);
    if (!$body) return ['added' => 0, 'error' => 'Semantic Scholar request failed (likely rate-limited — consider SEMANTIC_SCHOLAR_API_KEY)'];

    $data = json_decode($body, true);
    $papers = $data['data'] ?? [];
    $added = 0;
    foreach ($papers as $paper) {
        $title = $paper['title'] ?? null;
        $url = $paper['openAccessPdf']['url'] ?? $paper['url'] ?? null;
        if (!$title || !$url) continue;

        $authors = array_map(fn($a) => $a['name'] ?? '', $paper['authors'] ?? []);
        $fieldsOfStudy = array_filter($paper['fieldsOfStudy'] ?? []);

        $tags = array_unique(array_merge($fieldsOfStudy, classify_subjects($title . ' ' . ($paper['abstract'] ?? '')), array_filter([$subjectSlug])));

        $id = insert_item_if_new([
            'title' => $title, 'url' => $url,
            'authors' => implode(', ', array_filter($authors)),
            'abstract' => $paper['abstract'] ?? null,
            'source_name' => 'Semantic Scholar',
            'published_date' => isset($paper['year']) ? "{$paper['year']}-01-01" : null,
            'citation_count' => $paper['citationCount'] ?? null,
        ], $tags);
        if ($id) $added++;
    }
    return ['added' => $added];
}

// ---- API harvest: USPTO patents (PatentsView) ------------------------------
// Requires a free self-registered API key (patentsview.org/apis/keyrequest).
// No-ops quietly (not an error) when PATENTSVIEW_API_KEY isn't set in
// config.php — the rest of the harvester works fine without it.

function api_harvest_patentsview(string $subjectSlug, string $keyword, int $max = 8): array {
    if (!defined('PATENTSVIEW_API_KEY') || !PATENTSVIEW_API_KEY) {
        return ['added' => 0]; // not configured — silently skip, not an error
    }

    $query = json_encode(['_text_any' => ['patent_title' => $keyword]]);
    $fieldsParam = json_encode(['patent_id', 'patent_title', 'patent_date', 'patent_abstract', 'inventors.inventor_name_last']);
    $optionsParam = json_encode(['size' => $max]);
    $url = 'https://search.patentsview.org/api/v1/patent/'
        . '?q=' . urlencode($query) . '&f=' . urlencode($fieldsParam) . '&o=' . urlencode($optionsParam);

    $body = safe_http_get($url, ['X-Api-Key: ' . PATENTSVIEW_API_KEY]);
    if (!$body) return ['added' => 0, 'error' => 'PatentsView request failed'];

    $data = json_decode($body, true);
    $patents = $data['patents'] ?? [];
    $added = 0;
    foreach ($patents as $p) {
        $title = $p['patent_title'] ?? null;
        $patentId = $p['patent_id'] ?? null;
        if (!$title || !$patentId) continue;

        $inventors = array_map(fn($i) => $i['inventor_name_last'] ?? '', $p['inventors'] ?? []);
        $tags = array_unique(array_merge(classify_subjects($title . ' ' . ($p['patent_abstract'] ?? '')), array_filter([$subjectSlug])));

        $id = insert_item_if_new([
            'title' => $title,
            'url' => "https://patents.google.com/patent/US{$patentId}",
            'authors' => implode(', ', array_filter($inventors)),
            'abstract' => $p['patent_abstract'] ?? null,
            'source_name' => 'USPTO',
            'published_date' => $p['patent_date'] ?? null,
            'language' => 'en', // USPTO filings; PatentsView doesn't report one but this is a safe assumption
        ], $tags);
        if ($id) $added++;
    }
    return ['added' => $added];
}

// ---- Video harvest: YouTube + Vimeo ---------------------------------------
// A separate content_type ('video'), kept out of the main research catalog
// entirely (index.php, credits, activity chart, tags counts all filter to
// content_type='research') — surfaced only on videos.php. Both silently
// no-op when their key isn't configured, same pattern as PATENTSVIEW_API_KEY.

function api_harvest_youtube(string $subjectSlug, string $keyword, int $max = 8): array {
    if (!defined('YOUTUBE_API_KEY') || !YOUTUBE_API_KEY) {
        return ['added' => 0]; // not configured — silently skip, not an error
    }

    $q = urlencode($keyword);
    $key = urlencode(YOUTUBE_API_KEY);
    $body = safe_http_get("https://www.googleapis.com/youtube/v3/search?part=snippet&q={$q}&type=video&order=relevance&maxResults={$max}&key={$key}");
    if (!$body) return ['added' => 0, 'error' => 'YouTube request failed'];

    $data = json_decode($body, true);
    if (isset($data['error'])) return ['added' => 0, 'error' => 'YouTube: ' . ($data['error']['message'] ?? 'unknown error')];

    $items = $data['items'] ?? [];
    $added = 0;
    foreach ($items as $item) {
        $videoId = $item['id']['videoId'] ?? null;
        $snippet = $item['snippet'] ?? [];
        $title = $snippet['title'] ?? null;
        if (!$videoId || !$title) continue;

        $thumb = $snippet['thumbnails']['high']['url']
            ?? $snippet['thumbnails']['medium']['url']
            ?? $snippet['thumbnails']['default']['url'] ?? null;
        $tags = array_unique(array_merge(classify_subjects($title . ' ' . ($snippet['description'] ?? '')), array_filter([$subjectSlug])));

        $id = insert_item_if_new([
            'title' => $title,
            'url' => "https://www.youtube.com/watch?v={$videoId}",
            'abstract' => $snippet['description'] ?? null,
            'source_name' => $snippet['channelTitle'] ?? 'YouTube',
            'published_date' => isset($snippet['publishedAt']) ? substr($snippet['publishedAt'], 0, 10) : null,
            'image_url' => $thumb,
            'content_type' => 'video',
        ], $tags);
        if ($id) $added++;
    }
    return ['added' => $added];
}

function api_harvest_vimeo(string $subjectSlug, string $keyword, int $max = 8): array {
    if (!defined('VIMEO_ACCESS_TOKEN') || !VIMEO_ACCESS_TOKEN) {
        return ['added' => 0]; // not configured — silently skip, not an error
    }

    $q = urlencode($keyword);
    $headers = [
        'Authorization: Bearer ' . VIMEO_ACCESS_TOKEN,
        'Accept: application/vnd.vimeo.*+json;version=3.4',
    ];
    $body = safe_http_get("https://api.vimeo.com/videos?query={$q}&per_page={$max}&sort=relevant", $headers);
    if (!$body) return ['added' => 0, 'error' => 'Vimeo request failed'];

    $data = json_decode($body, true);
    if (isset($data['error'])) return ['added' => 0, 'error' => 'Vimeo: ' . $data['error']];

    $videos = $data['data'] ?? [];
    $added = 0;
    foreach ($videos as $v) {
        $title = $v['name'] ?? null;
        $url = $v['link'] ?? null;
        if (!$title || !$url) continue;

        $sizes = $v['pictures']['sizes'] ?? [];
        $thumb = $sizes ? end($sizes)['link'] ?? null : null;
        $published = $v['release_time'] ?? $v['created_time'] ?? null;
        $tags = array_unique(array_merge(classify_subjects($title . ' ' . ($v['description'] ?? '')), array_filter([$subjectSlug])));

        $id = insert_item_if_new([
            'title' => $title,
            'url' => $url,
            'abstract' => $v['description'] ?? null,
            'source_name' => $v['user']['name'] ?? 'Vimeo',
            'published_date' => $published ? substr($published, 0, 10) : null,
            'image_url' => $thumb,
            'content_type' => 'video',
        ], $tags);
        if ($id) $added++;
    }
    return ['added' => $added];
}

/**
 * Shared hosting often caps PHP execution time around 30s regardless of
 * set_time_limit(), and one subject x one API call already involves a
 * handful of sequential HTTP round trips. Rather than hit all subjects on
 * every cron tick (slow, risks a hard timeout mid-run), rotate through a
 * few subjects per run using a persisted cursor — full coverage happens
 * across several cron ticks instead of one. Measured locally: 2 subjects x
 * 6 sources took 64s — too close to a 30s shared-hosting cap. subjectsPerRun
 * drops to 1 (was 2) so a run is 1 subject x 6 sources (~30s worst case);
 * full 30+-subject coverage takes longer to cycle through but each run is
 * safely bounded regardless of which subject or how many sources are slow.
 */
/**
 * Per-source cooldown: a source that was called (successfully or not) within
 * the last hour is skipped rather than hit again. This is independent of
 * cron cadence — if cron runs more often than hourly, or "Run harvest now"
 * gets clicked repeatedly, sources still aren't hammered.
 */
function source_ready(string $source, int $cooldownSeconds = 3600): bool {
    $last = get_setting("source_last_called:{$source}");
    return !$last || (time() - strtotime($last)) >= $cooldownSeconds;
}

function mark_source_called(string $source): void {
    set_setting("source_last_called:{$source}", date('Y-m-d H:i:s'));
}

function run_api_harvest(array $subjects, int $perSourceMax = 5, int $subjectsPerRun = 1, ?float $deadline = null): array {
    $slugs = array_keys($subjects);
    $cursor = (int) get_setting('subject_cursor', '0') % count($slugs);
    $batch = [];
    for ($i = 0; $i < min($subjectsPerRun, count($slugs)); $i++) {
        $batch[] = $slugs[($cursor + $i) % count($slugs)];
    }
    set_setting('subject_cursor', (string) (($cursor + count($batch)) % count($slugs)));

    $sources = [
        'api_harvest_arxiv', 'api_harvest_crossref', 'api_harvest_pubmed',
        'api_harvest_openalex', 'api_harvest_semanticscholar', 'api_harvest_patentsview',
    ];

    $added = 0;
    $errors = [];
    $skipped = [];
    $stoppedEarly = false;
    foreach ($batch as $slug) {
        $keyword = $subjects[$slug]['keywords'][0];
        foreach ($sources as $fn) {
            if ($deadline !== null && time_budget_exceeded($deadline)) {
                $stoppedEarly = true;
                break 2;
            }
            if (!source_ready($fn)) {
                $skipped[] = $fn;
                continue;
            }
            mark_source_called($fn);
            try {
                $result = $fn($slug, $keyword, $perSourceMax);
                $added += $result['added'];
                if (!empty($result['error'])) $errors[] = "{$fn}({$slug}): {$result['error']}";
            } catch (Throwable $e) {
                db(true); // reconnect so the next source function gets a working connection
                $errors[] = "{$fn}({$slug}): " . $e->getMessage();
            }
        }
    }
    if ($stoppedEarly) {
        $errors[] = 'Stopped early: time budget exceeded — remaining sources resume next run.';
    }
    return ['added' => $added, 'errors' => $errors, 'subjects' => $batch, 'skipped' => array_unique($skipped)];
}

/**
 * Same subject-rotation shape as run_api_harvest(), kept fully independent
 * of it — own cursor setting key (doesn't skip/advance the research-catalog
 * rotation), own source-cooldown keys. No-ops cheaply if neither
 * YOUTUBE_API_KEY nor VIMEO_ACCESS_TOKEN is configured (each harvest
 * function checks its own key and returns early).
 */
function run_video_harvest(array $subjects, int $perSourceMax = 5, ?float $deadline = null): array {
    $slugs = array_keys($subjects);
    $cursor = (int) get_setting('video_subject_cursor', '0') % count($slugs);
    $slug = $slugs[$cursor];
    set_setting('video_subject_cursor', (string) (($cursor + 1) % count($slugs)));
    $keyword = $subjects[$slug]['keywords'][0];

    $sources = ['api_harvest_youtube', 'api_harvest_vimeo'];
    $added = 0;
    $errors = [];
    foreach ($sources as $fn) {
        if ($deadline !== null && time_budget_exceeded($deadline)) {
            $errors[] = 'Stopped early: time budget exceeded — remaining video sources resume next run.';
            break;
        }
        if (!source_ready($fn)) continue;
        mark_source_called($fn);
        try {
            $result = $fn($slug, $keyword, $perSourceMax);
            $added += $result['added'];
            if (!empty($result['error'])) $errors[] = "{$fn}({$slug}): {$result['error']}";
        } catch (Throwable $e) {
            db(true);
            $errors[] = "{$fn}({$slug}): " . $e->getMessage();
        }
    }
    return ['added' => $added, 'errors' => $errors];
}

/**
 * Fulfills queued zero-result public searches (see record_search_miss() in
 * functions.php) by running each one as a one-off keyword search across the
 * same API sources as the regular subject rotation. Uses its own cooldown
 * namespace per source (independent of the subject rotation's) so this
 * doesn't compete with or get blocked by the regular harvest's per-source
 * cooldowns within the same run. Passing '' as the subjectSlug means the
 * search query itself never gets added as a tag (see array_filter([$subjectSlug])
 * in each api_harvest_* function) — only whatever real subjects/topics the
 * results themselves classify into.
 */
function harvest_search_misses(int $maxQueries = 3, int $perSourceMax = 5, ?float $deadline = null): array {
    $sources = [
        'api_harvest_arxiv', 'api_harvest_crossref', 'api_harvest_pubmed',
        'api_harvest_openalex', 'api_harvest_semanticscholar', 'api_harvest_patentsview',
    ];

    $rows = db()->query(
        "SELECT id, query FROM search_misses WHERE harvested_at IS NULL
         ORDER BY search_count DESC, first_searched_at ASC LIMIT " . (int)$maxQueries
    )->fetchAll();

    $added = 0;
    $errors = [];
    foreach ($rows as $row) {
        if ($deadline !== null && time_budget_exceeded($deadline)) break;
        $queryAdded = 0;
        foreach ($sources as $fn) {
            if ($deadline !== null && time_budget_exceeded($deadline)) break 2;
            $searchSourceKey = "{$fn}_search";
            if (!source_ready($searchSourceKey, 1800)) continue;
            mark_source_called($searchSourceKey);
            try {
                $result = $fn('', $row['query'], $perSourceMax);
                $queryAdded += $result['added'];
                if (!empty($result['error'])) $errors[] = "{$fn}(search:\"{$row['query']}\"): {$result['error']}";
            } catch (Throwable $e) {
                db(true);
                $errors[] = "{$fn}(search:\"{$row['query']}\"): " . $e->getMessage();
            }
        }
        db()->prepare('UPDATE search_misses SET harvested_at = NOW(), items_found = ? WHERE id = ?')
            ->execute([$queryAdded, $row['id']]);
        $added += $queryAdded;
    }
    return ['added' => $added, 'errors' => $errors, 'queries_processed' => count($rows)];
}

// ---- Source discovery: find new seeds, feed the harvester ---------------
//
// Two mechanisms, both propose CANDIDATE seeds (active=0, discovered=1) for
// admin review in seeds.php — nothing here gets crawled automatically until
// approved. This keeps a human in the loop on which domains get trusted,
// while still surfacing genuinely new sources without manual searching.

function seed_host_known(string $host): bool {
    $stmt = db()->prepare('SELECT 1 FROM seed_urls WHERE host = ? LIMIT 1');
    $stmt->execute([$host]);
    return (bool) $stmt->fetchColumn();
}

function propose_seed(string $url, ?string $subjectSlug, string $discoverySource): bool {
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host || seed_host_known($host)) return false;

    try {
        $stmt = db()->prepare(
            'INSERT IGNORE INTO seed_urls (url, host, subject_slug, active, discovered, discovery_source)
             VALUES (?, ?, ?, 0, 1, ?)'
        );
        $stmt->execute([$url, $host, $subjectSlug, $discoverySource]);
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Mines OpenAlex's own curated Sources index (journals, repositories,
 * preprint servers — ~250k entries, free, no key) for well-established
 * research sources we don't already have a seed on. This is the primary
 * discovery mechanism: it proposes real, vetted repositories (Zenodo,
 * SSRN, HAL, DOAJ, RePEc, ...) rather than guessing from arbitrary crawled
 * links. Rotates through source *types* across runs the same way subject
 * rotation works, on its own (longer) cooldown — new sources don't appear
 * often enough to need hourly checking.
 */
function discover_sources_openalex(int $max = 15): array {
    if (!source_ready('discover_sources_openalex', 24 * 3600)) {
        return ['proposed' => 0, 'skipped' => true];
    }
    mark_source_called('discover_sources_openalex');

    $types = ['repository', 'journal'];
    $typeIndex = (int) get_setting('openalex_source_type_cursor', '0') % count($types);
    $type = $types[$typeIndex];
    set_setting('openalex_source_type_cursor', (string) (($typeIndex + 1) % count($types)));

    $contact = defined('CONTACT_EMAIL') ? '&mailto=' . urlencode(CONTACT_EMAIL) : '';
    $filter = urlencode("type:{$type},works_count:>5000");
    $body = safe_http_get("https://api.openalex.org/sources?filter={$filter}&sort=works_count:desc&per-page=" . ($max * 3) . $contact);
    if (!$body) return ['proposed' => 0, 'error' => 'OpenAlex Sources request failed'];

    $data = json_decode($body, true);
    $sources = $data['results'] ?? [];
    $proposed = 0;
    foreach ($sources as $source) {
        if ($proposed >= $max) break;
        $homepage = $source['homepage_url'] ?? null;
        if (!$homepage || !filter_var($homepage, FILTER_VALIDATE_URL)) continue;
        if (propose_seed($homepage, null, 'openalex-sources')) $proposed++;
    }
    return ['proposed' => $proposed];
}

/**
 * The organic complement to the OpenAlex mechanism: while the crawler is
 * already fetching pages discovered from seeds (process_queue_batch), a
 * page whose host we've never touched before and that itself contains many
 * distinct outbound links looks like a hub/listing/index page, not a single
 * piece of content — worth proposing as a seed in its own right. Cheap to
 * check since it reuses the body already fetched for metadata extraction,
 * no extra HTTP request.
 */
const HUB_CANDIDATE_MIN_DISTINCT_HOSTS = 8;

function maybe_flag_hub_candidate(string $url, string $body, bool $hostIsNew): void {
    if (!$hostIsNew) return;

    $host = parse_url($url, PHP_URL_HOST);
    if (!$host || seed_host_known($host)) return;

    $links = extract_links($body, $url);
    $distinctHosts = array_unique(array_filter(array_map(
        fn($l) => parse_url($l['url'], PHP_URL_HOST), $links
    )));

    if (count($distinctHosts) >= HUB_CANDIDATE_MIN_DISTINCT_HOSTS) {
        propose_seed($url, null, 'crawler-hub-heuristic');
    }
}

function discover_new_seeds(): array {
    $openalex = discover_sources_openalex();
    return ['proposed' => $openalex['proposed'] ?? 0, 'errors' => array_filter([$openalex['error'] ?? null])];
}

// ---- Bounded crawl: seed hubs -> crawl_queue -> items -----------------

/**
 * A seed that fails repeatedly (e.g. behind bot-protection like AWS WAF —
 * something a polite crawler correctly can't and shouldn't try to bypass)
 * would otherwise error on every single run forever. Past this many
 * consecutive failures it's auto-disabled instead, same pattern as
 * LINK_FAILURE_THRESHOLD for dead item links.
 */
const SEED_FAILURE_THRESHOLD = 3;

/**
 * Auto-disabled is not permanent — a seed can fail 3x for a transient
 * reason (temporary outage, momentary rate-limit) as easily as a permanent
 * one (bot-protection). After this cooldown it gets one more chance
 * automatically rather than needing a manual re-enable in seeds.php.
 * discovered=0 in the WHERE clause is deliberate: seeds pending admin
 * review (discovered=1, active=0) must stay inactive until approved —
 * this only ever touches seeds that were active and got disabled by
 * repeated failure, not ones that were never approved in the first place.
 */
const SEED_COOLDOWN_HOURS = 24;

/**
 * A seed can cycle disable -> 24h cooldown -> reactivate -> fail again
 * forever with no exit, for a site that's genuinely, persistently
 * bot-protected (ScienceDirect, Science, JAMA — confirmed on production,
 * these block on every attempt). Past this many consecutive cycles with
 * zero successes between them (~1 week, since each cycle is bounded by
 * SEED_COOLDOWN_HOURS), stop retrying entirely — see
 * disable_seed_after_failure(). Only a manual re-enable in seeds.php
 * clears permanently_disabled.
 */
const SEED_PERMANENT_DISABLE_CYCLES = 7;

/**
 * Shared by both places a seed gets disabled after SEED_FAILURE_THRESHOLD
 * consecutive failures (the normal fetch-failure path and the MySQL-
 * connection-failure catch block) so block_cycles/permanently_disabled
 * stay consistent regardless of which path triggered the disable.
 */
function disable_seed_after_failure(int $seedId, int $failures): void {
    $stmt = db()->prepare('SELECT block_cycles FROM seed_urls WHERE id = ?');
    $stmt->execute([$seedId]);
    $cycles = (int) $stmt->fetchColumn() + 1;
    $permanent = $cycles >= SEED_PERMANENT_DISABLE_CYCLES ? 1 : 0;
    db()->prepare(
        'UPDATE seed_urls SET last_crawled_at = NOW(), failed_fetches = ?, active = 0, block_cycles = ?, permanently_disabled = ? WHERE id = ?'
    )->execute([$failures, $cycles, $permanent, $seedId]);
}

function reactivate_cooled_down_seeds(): int {
    $stmt = db()->prepare(
        "UPDATE seed_urls
         SET active = 1, failed_fetches = 0
         WHERE active = 0 AND discovered = 0 AND permanently_disabled = 0
           AND failed_fetches >= ?
           AND last_crawled_at < DATE_SUB(NOW(), INTERVAL ? HOUR)"
    );
    $stmt->execute([SEED_FAILURE_THRESHOLD, SEED_COOLDOWN_HOURS]);
    return $stmt->rowCount();
}

/**
 * Cron now fires every 15 minutes instead of hourly — rather than every
 * tick trying all active seeds (redundant re-scanning of the same hub
 * pages 4x as often, and each full pass had already been taking several
 * minutes on its own), seeds are split into 4 non-overlapping groups by
 * `id % 4` and each 15-minute slot only crawls its own group. Every seed
 * still gets touched once per hour overall, just spread across 4 smaller,
 * faster runs instead of one large one. Mapping is fixed, not
 * config-driven: :15 -> group 0, :30 -> group 1, :45 -> group 2,
 * :00 -> group 3.
 */
function current_seed_group(): int {
    $minute = (int) date('i');
    return (intdiv($minute, 15) + 3) % 4;
}

/**
 * Round-robin group assignment via a persistent cursor (settings table),
 * not id % 4 — that drifts uneven once seeds get deleted (confirmed: one
 * group already sitting several seeds behind the others after normal
 * churn). Called once, at the moment a seed actually becomes active
 * (admin adds one directly, or approves a discovered one) — re-enabling a
 * previously-active seed keeps whatever group it already had rather than
 * reassigning, since it's not new.
 */
function assign_next_seed_group(int $seedId): void {
    $cursor = (int) get_setting('seed_group_cursor', '0') % 4;
    db()->prepare('UPDATE seed_urls SET seed_group = ? WHERE id = ?')->execute([$cursor, $seedId]);
    set_setting('seed_group_cursor', (string) (($cursor + 1) % 4));
}

function crawl_due_seeds(int $limit = 200, ?float $deadline = null): array {
    reactivate_cooled_down_seeds();

    $group = current_seed_group();
    $stmt = db()->prepare(
        "SELECT * FROM seed_urls WHERE active = 1 AND seed_group = ?
         ORDER BY (last_crawled_at IS NULL) DESC, last_crawled_at ASC
         LIMIT " . (int)$limit
    );
    $stmt->execute([$group]);
    $seeds = $stmt->fetchAll();

    $discovered = 0;
    $errors = [];
    foreach ($seeds as $seed) {
        if ($deadline !== null && time_budget_exceeded($deadline)) {
            $errors[] = 'Stopped early: time budget exceeded — remaining seeds resume next run.';
            break;
        }
        try {
            if (!can_crawl_url($seed['url'])) continue;
            $host = parse_url($seed['url'], PHP_URL_HOST);

            $body = safe_http_get($seed['url'], ["User-Agent: " . HARVEST_USER_AGENT]);
            mark_host_crawled($host);

            if (!$body) {
                $failures = (int) $seed['failed_fetches'] + 1;
                if ($failures >= SEED_FAILURE_THRESHOLD) {
                    disable_seed_after_failure((int)$seed['id'], $failures);
                    $cyclesNow = (int) db()->query('SELECT block_cycles FROM seed_urls WHERE id = ' . (int)$seed['id'])->fetchColumn();
                    $permNote = $cyclesNow >= SEED_PERMANENT_DISABLE_CYCLES
                        ? " — {$cyclesNow} consecutive block cycles, permanently disabled (re-enable in seeds.php to retry)"
                        : " — auto-disabled, cycle {$cyclesNow}/" . SEED_PERMANENT_DISABLE_CYCLES . " (likely bot-protected or unreachable; retries automatically in " . SEED_COOLDOWN_HOURS . "h)";
                    $errors[] = "seed {$seed['id']} ({$seed['url']}) fetch failed {$failures}x{$permNote}";
                } else {
                    db()->prepare('UPDATE seed_urls SET last_crawled_at = NOW(), failed_fetches = ? WHERE id = ?')
                        ->execute([$failures, $seed['id']]);
                    $errors[] = "seed {$seed['id']} ({$seed['url']}) fetch failed ({$failures}/" . SEED_FAILURE_THRESHOLD . ")";
                }
                continue;
            }

            db()->prepare('UPDATE seed_urls SET last_crawled_at = NOW(), failed_fetches = 0, successful_fetches = successful_fetches + 1, block_cycles = 0 WHERE id = ?')->execute([$seed['id']]);

            $links = extract_links($body, $seed['url']);
            // Filters against robots.txt for hosts we already have cached
            // data for (e.g. arxiv.org, hit repeatedly across many seed
            // crawls) — that's where the original 79%-wasted-queue-rows
            // problem actually was. Deliberately does NOT trigger a live
            // fetch for a host we've never seen (get_cached_host(), not
            // get_or_fetch_host()) — queues those optimistically instead,
            // and process_queue_batch()'s own robots check catches them
            // later, one at a time, when they're actually processed rather
            // than as a synchronous burst here. See get_cached_host()'s
            // comment for what happened when this fetched eagerly.
            $hostRowCache = [];
            foreach ($links as $link) {
                $hash = url_hash($link['url']);
                $linkHost = parse_url($link['url'], PHP_URL_HOST);
                if (!$linkHost) continue;

                if (!array_key_exists($linkHost, $hostRowCache)) {
                    $hostRowCache[$linkHost] = get_cached_host($linkHost);
                }
                if ($hostRowCache[$linkHost] !== null) {
                    $linkPath = parse_url($link['url'], PHP_URL_PATH) ?? '/';
                    if (!robots_path_allowed($hostRowCache[$linkHost], $linkPath)) {
                        continue;
                    }
                }

                try {
                    // INSERT IGNORE never throws on a duplicate key — that's
                    // the whole point of IGNORE — so the catch below was
                    // never actually reached by "already queued" links; it
                    // was silently counting every re-scan of the same seed
                    // page as a fresh "discovery" even when every single
                    // link was already in the queue from an hour ago.
                    // rowCount() is the only way to tell whether this
                    // execute() actually inserted a new row (1) or was a
                    // silent no-op on an existing url_hash (0). Confirmed
                    // on production: harvest_log reported ~3,300
                    // "discovered" per run while only 4–17 rows/hour were
                    // genuinely new — seeds get fully re-scanned every run,
                    // and almost everything found is a link already queued
                    // from a previous hour.
                    $stmt = db()->prepare(
                        'INSERT IGNORE INTO crawl_queue (url, url_hash, host, subject_slug) VALUES (?, ?, ?, ?)'
                    );
                    $stmt->execute([$link['url'], $hash, $linkHost, $seed['subject_slug']]);
                    if ($stmt->rowCount() > 0) {
                        $discovered++;
                    }
                } catch (Throwable $e) {
                    // malformed url_hash collision or similar, skip
                }
            }
        } catch (Throwable $e) {
            // A dead connection (e.g. "MySQL server has gone away" after a
            // slow fetch above) or any other failure here must not crash the
            // whole run — reconnect so the next seed gets a working
            // connection, log it, and move on.
            db(true);
            $errors[] = "seed {$seed['id']} ({$seed['url']}): " . $e->getMessage();

            // This path used to skip last_crawled_at/failed_fetches
            // entirely, unlike the normal fetch-failure path below — which
            // left a seed that reliably triggers this exception (a
            // consistently slow page holding the connection open past
            // MySQL's wait_timeout) permanently stuck at the front of the
            // "oldest first" rotation. Confirmed on production: one seed
            // had last_crawled_at NULL after a full week — hitting this
            // exact error on literally every single run, forever, since it
            // never aged out or accumulated toward auto-disable like any
            // other failure does.
            try {
                $failures = (int) $seed['failed_fetches'] + 1;
                if ($failures >= SEED_FAILURE_THRESHOLD) {
                    disable_seed_after_failure((int)$seed['id'], $failures);
                } else {
                    db()->prepare('UPDATE seed_urls SET last_crawled_at = NOW(), failed_fetches = ? WHERE id = ?')
                        ->execute([$failures, $seed['id']]);
                }
            } catch (Throwable $e2) {
                // Even the reconnect+update attempt failed; move on rather
                // than crash the whole batch over one row.
            }
        }
    }
    return ['discovered' => $discovered, 'errors' => $errors];
}

function host_known_before_this_run(string $host): bool {
    $stmt = db()->prepare('SELECT 1 FROM hosts WHERE host = ? LIMIT 1');
    $stmt->execute([$host]);
    return (bool) $stmt->fetchColumn();
}

function process_queue_batch(int $limit = 20, ?float $deadline = null): array {
    $stmt = db()->query(
        "SELECT * FROM crawl_queue WHERE status = 'pending' ORDER BY discovered_at ASC LIMIT " . (int)$limit
    );
    $rows = $stmt->fetchAll();

    $added = 0;
    $errors = 0;
    foreach ($rows as $row) {
        if ($deadline !== null && time_budget_exceeded($deadline)) {
            break; // remaining rows stay 'pending', picked up next run
        }
        try {
            // Checked before touching the hosts table, so it reflects
            // whether we'd ever seen this host prior to this run.
            $hostIsNew = !host_known_before_this_run($row['host']);

            $parts = parse_url($row['url']);
            $path = $parts['path'] ?? '/';
            $hostRow = get_or_fetch_host($row['host']);

            // robots.txt disallow is a *permanent* verdict for this URL —
            // marking it 'skipped' resolves it for good. Previously this
            // fell through to a bare `continue`, leaving the row 'pending'
            // forever; since the queue is FIFO (oldest first) and only
            // $limit rows get pulled per run, permanently-disallowed URLs
            // piled up at the front and starved every legitimately
            // fetchable URL behind them from ever being tried (confirmed on
            // production: ~4,000 pending rows, arxiv.org/search and
            // similar robots-disallowed paths stuck at the head of the
            // queue since the very first crawl).
            if (!robots_path_allowed($hostRow, $path)) {
                db()->prepare("UPDATE crawl_queue SET status='skipped', processed_at=NOW(), error='robots.txt disallow' WHERE id=?")
                    ->execute([$row['id']]);
                continue;
            }

            // Crawl-delay not yet elapsed is transient — genuinely leave
            // this one 'pending' to retry once the host cools down.
            if (!host_ready_to_crawl($hostRow)) {
                continue;
            }

            $body = safe_http_get($row['url'], ['User-Agent: ' . HARVEST_USER_AGENT]);
            mark_host_crawled($row['host']);

            if (!$body) {
                db()->prepare("UPDATE crawl_queue SET status='skipped', processed_at=NOW() WHERE id=?")->execute([$row['id']]);
                continue;
            }

            $meta = extract_generic_metadata($body, $row['url']);
            maybe_flag_hub_candidate($row['url'], $body, $hostIsNew);

            if (!$meta['title'] || looks_like_site_branding($meta['title'], $row['host'])) {
                db()->prepare("UPDATE crawl_queue SET status='skipped', processed_at=NOW() WHERE id=?")->execute([$row['id']]);
                continue;
            }

            // Falls back to a plain-text slice of the page body when there's
            // no og:description/description meta tag -- generic crawled
            // pages frequently lack one, which otherwise starves
            // classify_subjects() down to just the (short) title and was
            // the main source of zero-tag items.
            $classifyText = $meta['title'] . ' ' . ($meta['abstract'] ?? '');
            if (trim((string) ($meta['abstract'] ?? '')) === '') {
                $classifyText .= ' ' . extract_body_text($body);
            }
            $subjects = array_filter([$row['subject_slug']]);
            $subjects = array_unique(array_merge($subjects, classify_subjects($classifyText)));

            $id = insert_item_if_new([
                'title' => $meta['title'], 'url' => $row['url'],
                'authors' => $meta['authors'], 'abstract' => $meta['abstract'],
                'source_name' => $meta['source_name'], 'published_date' => $meta['published_date'],
                'image_url' => $meta['image_url'], 'language' => $meta['language'] ?? null,
            ], $subjects);

            if ($id) $added++;
            db()->prepare("UPDATE crawl_queue SET status='done', processed_at=NOW() WHERE id=?")->execute([$row['id']]);
        } catch (Throwable $e) {
            $errors++;
            // Reconnect BEFORE using db() again — if a dead connection is
            // what threw in the first place, logging the error would fail
            // too otherwise, masking the real problem with a second one.
            db(true);
            try {
                db()->prepare("UPDATE crawl_queue SET status='error', processed_at=NOW(), error=? WHERE id=?")
                    ->execute([$e->getMessage(), $row['id']]);
            } catch (Throwable $e2) {
                // Even the reconnect+log attempt failed; move on rather than
                // crash the whole batch over one row.
            }
        }
    }
    return ['added' => $added, 'errors' => $errors];
}

// ---- Link health: verify existing items, remove ones that are truly dead --

/**
 * A single failed check can be transient, so items aren't removed on the
 * first failure -- including a 404/410, which used to skip this grace
 * period entirely on the theory that those codes unambiguously mean "this
 * no longer exists." Confirmed false on production: a HEAD-intolerant
 * server (archivalia.hypotheses.org) returned a 404 to HEAD for a page
 * that returned 200 with real content on GET, and the item was deleted on
 * its very first check. check_url_status() now retries with GET before
 * trusting any error code, which closes that specific gap -- but a
 * server's bot-detection blocking every method identically (a false 404
 * regardless of HEAD/GET) is a different failure mode that retry can't
 * distinguish from a real 404. Every code, however unambiguous it looks,
 * goes through the same grace period now, consistently.
 */
const LINK_FAILURE_THRESHOLD = 3;

/**
 * Random sampling, not oldest-first FIFO over a "due" queue — a queue-based
 * approach means the backlog only ever grows as the catalog grows (more
 * items added per run than 8/run can ever clear), same shape of problem
 * already fixed for crawl_due_seeds()/process_queue_batch(). Random
 * sampling has no backlog to fall behind on: every run is an independent
 * spot-check, coverage is just statistically thinner as the catalog grows
 * rather than falling further behind literally forever. Readers can also
 * flag a specific dead link directly (see report_broken_link.php) instead
 * of waiting on this to eventually sample it.
 */
function check_links_batch(int $limit = 8, ?float $deadline = null): array {
    $rows = db()->query(
        "SELECT id, url, failed_checks FROM items ORDER BY RAND() LIMIT " . (int)$limit
    )->fetchAll();

    $checked = 0;
    $removed = 0;
    foreach ($rows as $row) {
        if ($deadline !== null && time_budget_exceeded($deadline)) {
            break; // remaining items just wait for next run's check
        }
        $code = check_url_status($row['url']);
        $checked++;

        if ($code !== null && $code < 400) {
            db()->prepare('UPDATE items SET last_checked_at = NOW(), failed_checks = 0 WHERE id = ?')
                ->execute([$row['id']]);
            continue;
        }

        // Every failure counts the same way now, 404/410 included -- see
        // LINK_FAILURE_THRESHOLD's own comment for why the old "delete
        // immediately" special case for those two codes was removed.
        $failures = (int)$row['failed_checks'] + 1;
        if ($failures >= LINK_FAILURE_THRESHOLD) {
            db()->prepare('DELETE FROM items WHERE id = ?')->execute([$row['id']]);
            $removed++;
        } else {
            db()->prepare('UPDATE items SET last_checked_at = NOW(), failed_checks = ? WHERE id = ?')
                ->execute([$failures, $row['id']]);
        }
    }

    return ['checked' => $checked, 'removed' => $removed];
}

/**
 * One-time backlog cleanup for items tagged before classify_subjects()
 * switched to word-boundary matching (see includes/functions.php) --
 * reconciles each item's taxonomy tags (subjects.php slugs only; arXiv
 * categories, Crossref/OpenAlex subjects, and manually typed tags are left
 * alone) against what the fixed matcher would assign today. Runs a slice
 * per harvest via a persistent 'retag_cursor' setting (same cursor-in-
 * settings shape as subject_cursor/seed_group_cursor) so the full backlog
 * drains over several runs without a schema migration or a one-off admin
 * click. Naturally becomes a fast no-op forever once the cursor reaches the
 * end -- new items are already tagged correctly at insert time.
 */
function retag_backlog_batch(int $limit, ?float $deadline = null): array {
    $taxonomySlugs = array_keys(get_subjects());

    // 'retag_cursor_v2', not 'retag_cursor' -- the first version of this
    // pass (cursor key 'retag_cursor') could strip an item's only tag
    // (a false positive) and leave it with zero tags total, orphaned: this
    // batch's own cursor had already moved past that id by the time it
    // happened, and the separate classify_zero_tag_backlog() cursor moves
    // ~30x slower (live HTTP fetches vs. pure DB), so it could never catch
    // up before more items got orphaned than it fixed. Confirmed on
    // production: zero-tag items went 379 -> 1032 rather than shrinking.
    // The new key restarts the sweep from id 0 with the inline rescue
    // below, which closes that gap at the source instead of depending on
    // a second, slower pass to clean up after the first.
    $cursor = (int) get_setting('retag_cursor_v2', '0');
    $maxId = (int) db()->query('SELECT MAX(id) FROM items')->fetchColumn();
    if ($maxId === 0 || $cursor >= $maxId) {
        return ['checked' => 0, 'retagged' => 0, 'rescued' => 0, 'done' => true];
    }

    $stmt = db()->prepare('SELECT id, title, url, abstract FROM items WHERE id > ? ORDER BY id ASC LIMIT ' . (int) $limit);
    $stmt->execute([$cursor]);
    $rows = $stmt->fetchAll();

    $checked = 0;
    $retagged = 0;
    $rescued = 0;
    $lastId = $cursor;
    foreach ($rows as $row) {
        $lastId = (int) $row['id'];
        if ($deadline !== null && time_budget_exceeded($deadline)) break;
        $checked++;

        $currentTags = get_item_tags($lastId);
        $currentTaxonomyTags = array_values(array_filter(
            $currentTags,
            fn($t) => in_array($t['slug'], $taxonomySlugs, true)
        ));
        $otherTagCount = count($currentTags) - count($currentTaxonomyTags);
        $newMatches = classify_subjects(trim(($row['title'] ?? '') . ' ' . ($row['abstract'] ?? '')));

        $changed = false;
        foreach ($currentTaxonomyTags as $t) {
            if (!in_array($t['slug'], $newMatches, true)) {
                db()->prepare('DELETE FROM item_tags WHERE item_id = ? AND tag_id = ?')->execute([$lastId, $t['id']]);
                $changed = true;
            }
        }
        $currentTaxonomySlugs = array_column($currentTaxonomyTags, 'slug');
        foreach (array_diff($newMatches, $currentTaxonomySlugs) as $slug) {
            foreach (resolve_tag_ids($slug) as $tagId) {
                db()->prepare('INSERT IGNORE INTO item_tags (item_id, tag_id) VALUES (?, ?)')->execute([$lastId, $tagId]);
            }
            $changed = true;
        }
        if ($changed) $retagged++;

        // This item has zero tags of any kind (not just zero taxonomy tags)
        // once the reconciliation above lands -- rescue it right here with
        // the same body-text fetch classify_zero_tag_backlog() does, rather
        // than leaving it for a background scan that moves far slower than
        // this one and may never reach it. Falls back to 'general'
        // (subjects.php) if even that finds nothing, so this item is
        // closed out for good instead of staying zero-tagged.
        if ($otherTagCount === 0 && !$newMatches) {
            $body = safe_http_get($row['url'], ['User-Agent: ' . HARVEST_USER_AGENT]);
            $rescueMatches = $body
                ? classify_subjects(trim(($row['title'] ?? '') . ' ' . ($row['abstract'] ?? '')) . ' ' . extract_body_text($body))
                : [];
            set_item_tags($lastId, resolve_tag_ids(implode(',', $rescueMatches ?: ['general'])));
            $rescued++;
        }
    }
    set_setting('retag_cursor_v2', (string) $lastId);

    return ['checked' => $checked, 'retagged' => $retagged, 'rescued' => $rescued, 'done' => $lastId >= $maxId];
}

/**
 * Items with zero tags at all only reach that state via the generic seed
 * crawler (every API harvest path always attaches at least the subject slug
 * it was searching for) -- a seed with no subject_slug plus a page with no
 * description meta tag leaves classify_subjects() nothing to match. This
 * re-fetches each such item's URL, pulls a plain-text body snippet
 * (extract_body_text()), and reclassifies against title+abstract+body.
 * Own 'zero_tag_cursor' setting, same batched-background shape as
 * retag_backlog_batch(); a small limit since this does live HTTP fetches
 * (unlike the pure-DB retag pass).
 */
function classify_zero_tag_backlog(int $limit, ?float $deadline = null): array {
    // Randomly sampled from the live "currently zero tags" set every call --
    // NOT a monotonic id cursor. A cursor here had the exact same failure
    // mode as retag_backlog_batch()'s first version: any item the rescue
    // fetch failed to classify (dead link, timeout, no matching keywords
    // even with body text) sits behind a forward-only pointer forever,
    // reported as "done" once the cursor outruns the table while genuinely
    // unclassified items pile up behind it. Confirmed on production: this
    // reported done=true with 730 zero-tag items still remaining. Same
    // random-sampling fix already used by check_links_batch() for the same
    // FIFO-starvation reason -- items that get tagged simply drop out of
    // this query's result set, no bookkeeping needed to avoid reprocessing
    // them, and nothing can ever get permanently stuck behind a stale cursor.
    $stmt = db()->prepare(
        'SELECT i.id, i.title, i.url, i.abstract FROM items i
         LEFT JOIN item_tags it ON it.item_id = i.id
         WHERE it.item_id IS NULL
         ORDER BY RAND() LIMIT ' . (int) $limit
    );
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $checked = 0;
    $tagged = 0;
    $fallback = 0;
    foreach ($rows as $row) {
        if ($deadline !== null && time_budget_exceeded($deadline)) break;
        $checked++;

        $text = trim(($row['title'] ?? '') . ' ' . ($row['abstract'] ?? ''));
        $body = safe_http_get($row['url'], ['User-Agent: ' . HARVEST_USER_AGENT]);
        if ($body) {
            $text .= ' ' . extract_body_text($body);
        }

        $matches = classify_subjects($text);
        if ($matches) {
            set_item_tags((int) $row['id'], resolve_tag_ids(implode(',', $matches)));
            $tagged++;
        } else {
            // No keyword match even with body text, or the fetch itself
            // failed (dead link, timeout) -- 'general' (subjects.php)
            // closes this out for good rather than leaving it to be
            // resampled by RAND() forever. It drops out of the zero-tag
            // query below either way, same as a real match would.
            set_item_tags((int) $row['id'], resolve_tag_ids('general'));
            $fallback++;
        }
    }

    // No rows at all means the zero-tag set is genuinely empty right now --
    // the only honest "done" signal under random sampling.
    return ['checked' => $checked, 'tagged' => $tagged, 'fallback' => $fallback, 'done' => count($rows) === 0];
}

/**
 * 'general' is a fallback, not a resting state -- without something that
 * keeps trying to upgrade it, it's just "zero tags" wearing a label
 * (fair criticism, worth taking seriously). This retries classification
 * for items currently stuck on 'general', which can succeed now even
 * when it failed before for two independent reasons: the taxonomy grows
 * over time (subjects_admin.php lets an admin add new subjects any time,
 * and the seed list itself went 26 -> 85 subjects in one pass), and this
 * re-fetches the page fresh in case the original attempt hit a transient
 * failure rather than genuinely unclassifiable content.
 *
 * Random-sampled, not a cursor -- same FIFO-starvation reasoning as
 * classify_zero_tag_backlog(): an item that upgrades simply stops
 * matching this query, no bookkeeping needed, and nothing can get stuck
 * behind a stale pointer. Safe to keep running indefinitely as the
 * taxonomy keeps growing, unlike a one-shot cursor sweep that would need
 * manually restarting (a new cursor key) every time the taxonomy changes.
 */
function reclassify_general_backlog(int $limit, ?float $deadline = null): array {
    $stmt = db()->prepare(
        "SELECT i.id, i.title, i.url, i.abstract FROM items i
         JOIN item_tags it ON it.item_id = i.id
         JOIN tags t ON t.id = it.tag_id
         WHERE t.slug = 'general'
         ORDER BY RAND() LIMIT " . (int) $limit
    );
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $checked = 0;
    $upgraded = 0;
    foreach ($rows as $row) {
        if ($deadline !== null && time_budget_exceeded($deadline)) break;
        $checked++;

        $text = trim(($row['title'] ?? '') . ' ' . ($row['abstract'] ?? ''));
        $matches = classify_subjects($text);
        if (!$matches) {
            $body = safe_http_get($row['url'], ['User-Agent: ' . HARVEST_USER_AGENT]);
            if ($body) {
                $matches = classify_subjects($text . ' ' . extract_body_text($body));
            }
        }

        if ($matches) {
            $itemId = (int) $row['id'];
            $keepNames = array_map(
                fn($t) => $t['name'],
                array_filter(get_item_tags($itemId), fn($t) => $t['slug'] !== 'general')
            );
            set_item_tags($itemId, resolve_tag_ids(implode(',', array_merge($keepNames, $matches))));
            $upgraded++;
        }
    }

    return ['checked' => $checked, 'upgraded' => $upgraded];
}

/**
 * Backfill for items.language on items added before language detection
 * existed (see ensure_items_language_column() in functions.php) -- same
 * "keep trying, don't just label it and move on" principle as
 * reclassify_general_backlog().
 *
 * NULL vs '' matters here: NULL means "never checked yet", '' means
 * "checked, genuinely couldn't determine" (fetch failed, no lang
 * attribute or og:locale). Only NULL rows are selected, so a '' result
 * drops out of the random-sample pool same as a real detection would --
 * without that distinction this would refetch the same permanently-
 * undetectable pages forever, same FIFO-starvation class of bug fixed
 * earlier in classify_zero_tag_backlog().
 *
 * arXiv/USPTO skip the fetch entirely and reuse the same assumption
 * applied at insert time (near-universally English in practice) --
 * cheap and avoids ~1,000+ needless re-fetches of sources where the
 * answer is already known with high confidence.
 */
function backfill_language_batch(int $limit, ?float $deadline = null): array {
    $stmt = db()->prepare(
        'SELECT id, url, source_name FROM items WHERE language IS NULL ORDER BY RAND() LIMIT ' . (int) $limit
    );
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $checked = 0;
    $detected = 0;
    foreach ($rows as $row) {
        if ($deadline !== null && time_budget_exceeded($deadline)) break;
        $checked++;
        $itemId = (int) $row['id'];

        $source = mb_strtolower(trim((string) $row['source_name']));
        if ($source === 'arxiv' || $source === 'uspto') {
            db()->prepare('UPDATE items SET language = ? WHERE id = ?')->execute(['en', $itemId]);
            $detected++;
            continue;
        }

        $language = '';
        $body = safe_http_get($row['url'], ['User-Agent: ' . HARVEST_USER_AGENT]);
        if ($body) {
            $language = extract_generic_metadata($body, $row['url'])['language'] ?? '';
        }
        db()->prepare('UPDATE items SET language = ? WHERE id = ?')->execute([$language, $itemId]);
        if ($language !== '') $detected++;
    }

    return ['checked' => $checked, 'detected' => $detected];
}

// ---- Orchestrator -------------------------------------------------------

/**
 * Several messages pushed into $errors are informational, not failures —
 * a source correctly self-throttling ("called within the last hour"), a
 * loop gracefully stopping at its time budget, a run declining to overlap
 * a previous one. Confirmed on production: these made up 29 of 53 "errors"
 * logged in one 8-hour window, none of them a real problem — just the
 * per-source cooldown doing exactly what it's supposed to now that harvest
 * runs 4x/hour instead of hourly. Still kept in `detail` (still useful
 * context for what a run actually did), just not counted toward the
 * numeric `errors` column that's meant to flag runs worth looking at.
 */
function count_real_errors(array $errors): int {
    $noticePrefixes = [
        'Skipped (called within the last hour)',
        'Skipped: a previous harvest run',
        'Skipped: a previous discovery run',
        'Skipped: a harvest run already started',
        'Stopped early: time budget exceeded',
        'Tag cleanup:',
    ];
    return count(array_filter($errors, function ($e) use ($noticePrefixes) {
        foreach ($noticePrefixes as $prefix) {
            if (str_starts_with($e, $prefix)) return false;
        }
        return true;
    }));
}

// Must stay under the cron interval so a genuinely-stuck run's lock
// self-heals before the next tick would otherwise be blocked forever. Cron
// now fires every 15 minutes (was hourly) — shortened to match, so a
// crashed run's lock clears before the very next slot instead of
// potentially blocking up to 4 ticks under the old 59-minute timeout.
const HARVEST_MAX_RUNTIME_MINUTES = 14;

/**
 * True if a harvest run has already started in this 15-minute slot
 * (:00/:15/:30/:45). A hard cap independent of the lock above — the lock
 * only stops two runs overlapping in time, it doesn't stop a stray extra
 * cron entry (or a misfiring host scheduler) from firing a second complete
 * run within the same slot once the first one has already finished and
 * released its lock. Was "already ran this hour" — cron firing 4x/hour is
 * now intentional (see current_seed_group()), not a misconfiguration to
 * guard against, so the cap moved from hourly to per-slot instead of being
 * removed outright.
 */
function harvest_already_ran_this_slot(): bool {
    $minute = (int) date('i');
    $slotStart = date('Y-m-d H:') . str_pad((string)(intdiv($minute, 15) * 15), 2, '0', STR_PAD_LEFT) . ':00';
    $stmt = db()->prepare("SELECT 1 FROM harvest_log WHERE run_type = 'harvest' AND started_at >= ? LIMIT 1");
    $stmt->execute([$slotStart]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Content harvest: API sources + crawl + link-health. Meant to run
 * frequently (harvest.php on its own cron entry) — everything in here is
 * already internally cooldown-gated per source, so frequent invocations
 * mostly no-op cheaply rather than doing redundant work.
 */
function run_content_harvest(): array {
    if (harvest_already_ran_this_slot()) {
        return [
            'items_added' => 0, 'links_discovered' => 0, 'links_checked' => 0,
            'items_removed' => 0, 'new_hosts_discovered' => 0,
            'errors' => ['Skipped: a harvest run already started in this 15-minute slot — capped to one per slot regardless of how many times this gets invoked.'],
        ];
    }

    if (!acquire_run_lock('harvest', HARVEST_MAX_RUNTIME_MINUTES)) {
        return [
            'items_added' => 0, 'links_discovered' => 0, 'links_checked' => 0,
            'items_removed' => 0, 'new_hosts_discovered' => 0,
            'errors' => ['Skipped: a previous harvest run is still within its ' . HARVEST_MAX_RUNTIME_MINUTES . '-minute window — avoiding an overlapping run.'],
        ];
    }

    $deadline = microtime(true) + HARVEST_MAX_RUNTIME_MINUTES * 60;
    $subjects = get_subjects();
    $logPdo = db();
    $logPdo->prepare("INSERT INTO harvest_log (started_at, run_type) VALUES (NOW(), 'harvest')")->execute();
    $logId = (int) $logPdo->lastInsertId();

    $itemsAdded = 0;
    $linksDiscovered = 0;
    $linksChecked = 0;
    $itemsRemoved = 0;
    $newHostsDiscovered = 0;
    $queueErrors = 0;
    $errors = [];

    // A crash partway through (a slow external API triggering the shared
    // host's MySQL wait_timeout mid-run has caused this before — see
    // db()'s reconnect logic) must still leave an honest, finished log row
    // instead of one stuck showing "running…" forever. The lock release
    // below must run regardless too, or a crash would leave it stuck
    // "locked" for the full window instead of self-healing immediately.
    try {
        $hostsBefore = (int) db()->query('SELECT COUNT(*) FROM hosts')->fetchColumn();

        $api = run_api_harvest($subjects, 5, 1, $deadline);
        $itemsAdded += $api['added'];
        $errors = array_merge($errors, $api['errors']);
        if ($api['skipped']) {
            $errors[] = 'Skipped (called within the last hour): ' . implode(', ', $api['skipped']);
        }

        $searchMisses = harvest_search_misses(3, 5, $deadline);
        $itemsAdded += $searchMisses['added'];
        $errors = array_merge($errors, $searchMisses['errors']);

        // Video items (content_type='video') never count toward the
        // research-catalog $itemsAdded total shown in the harvest log — kept
        // as a separate figure so "items added" there keeps meaning
        // "research catalog grew by N", not conflated with video counts.
        $video = run_video_harvest($subjects, 5, $deadline);
        $videoItemsAdded = $video['added'];
        $errors = array_merge($errors, $video['errors']);

        // 200 comfortably covers a quarter of the active-seed count (see
        // current_seed_group() — each 15-minute slot only crawls its own
        // group of ~1/4 the seeds now), with the deadline check inside the
        // loop still protecting against this eating the whole time budget
        // if the seed list grows a lot further.
        $seeds = crawl_due_seeds(200, $deadline);
        $linksDiscovered = $seeds['discovered'];
        $errors = array_merge($errors, $seeds['errors']);

        // Was 20 — sized for a much more frequent cron cadence. Now that
        // harvest is capped to one run per hour, 20/run would take the
        // ~4,000-item backlog (see the robots.txt-starvation fix above)
        // roughly 8+ days to drain even after unblocking it. The deadline
        // budget (59 min) easily absorbs a much larger batch since a fetch
        // is a couple seconds at most.
        // Was 150 — confirmed on production the backlog (4,771 pending
        // across 402 hosts) is growing faster than that drains it, since
        // seeds discover more per run than this processes. The deadline
        // check inside the loop still bails out safely if this ever runs
        // long, so raising the nominal cap just means more gets attempted
        // when there's time budget to spare, not a hard commitment.
        $queue = process_queue_batch(300, $deadline);
        $itemsAdded += $queue['added'];
        $queueErrors = $queue['errors'];

        $linkCheck = check_links_batch(8, $deadline);
        $linksChecked = $linkCheck['checked'];
        $itemsRemoved = $linkCheck['removed'];

        // Backlog cleanup, both purely additive to whatever time budget is
        // left after the steps above -- see retag_backlog_batch() and
        // classify_zero_tag_backlog() for why these run as background
        // batches instead of a one-off admin action.
        $retag = retag_backlog_batch(500, $deadline);
        $zeroTag = classify_zero_tag_backlog(15, $deadline);
        $general = reclassify_general_backlog(15, $deadline);
        $language = backfill_language_batch(15, $deadline);
        if ($retag['checked'] > 0 || $zeroTag['checked'] > 0 || $general['checked'] > 0 || $language['checked'] > 0) {
            $errors[] = "Tag cleanup: reviewed {$retag['checked']} existing item(s), retagged {$retag['retagged']}, "
                . "rescued {$retag['rescued']} newly-zero-tag; zero-tag scan checked {$zeroTag['checked']}, "
                . "tagged {$zeroTag['tagged']}, fell back to General for {$zeroTag['fallback']}; "
                . "General-reclassify checked {$general['checked']}, upgraded {$general['upgraded']}; "
                . "language backfill checked {$language['checked']}, detected {$language['detected']}.";
        }

        // Every new row in `hosts` since this run started is a domain the
        // crawler had never touched before — a concrete, honest measure of
        // "new sources found on the internet" via link-following from known
        // research entry points (not a claim of discovering unknown APIs).
        $hostsAfter = (int) db()->query('SELECT COUNT(*) FROM hosts')->fetchColumn();
        $newHostsDiscovered = max(0, $hostsAfter - $hostsBefore);
    } catch (Throwable $e) {
        $errors[] = 'FATAL: ' . $e->getMessage();
    } finally {
        release_run_lock('harvest');
    }

    db()->prepare(
        'UPDATE harvest_log
         SET finished_at = NOW(), items_added = ?, links_discovered = ?, links_checked = ?,
             items_removed = ?, new_hosts_discovered = ?, errors = ?, detail = ?
         WHERE id = ?'
    )->execute([
        $itemsAdded, $linksDiscovered, $linksChecked, $itemsRemoved,
        $newHostsDiscovered, count_real_errors($errors) + $queueErrors, implode("\n", $errors), $logId,
    ]);

    return [
        'items_added' => $itemsAdded,
        'links_discovered' => $linksDiscovered,
        'links_checked' => $linksChecked,
        'items_removed' => $itemsRemoved,
        'new_hosts_discovered' => $newHostsDiscovered,
        'errors' => $errors,
    ];
}

// Must stay under the cron interval (30 min) so a genuinely-stuck run's
// lock self-heals before the next tick would otherwise be blocked forever.
const DISCOVERY_MAX_RUNTIME_MINUTES = 29;

/**
 * Source discovery only (§4.2.1 in DESIGN.md): proposes new seeds, doesn't
 * crawl or harvest content. Meant to run on its own, less-frequent cron
 * entry (discover.php) — decoupled from content harvest since new sources
 * don't appear often enough to need the same cadence, and internally this
 * is already gated to once per 24h regardless of how often it's invoked.
 */
function run_source_discovery(): array {
    if (!acquire_run_lock('discovery', DISCOVERY_MAX_RUNTIME_MINUTES)) {
        return [
            'new_seeds_discovered' => 0,
            'errors' => ['Skipped: a previous discovery run is still within its ' . DISCOVERY_MAX_RUNTIME_MINUTES . '-minute window — avoiding an overlapping run.'],
        ];
    }

    $logPdo = db();
    $logPdo->prepare("INSERT INTO harvest_log (started_at, run_type) VALUES (NOW(), 'discovery')")->execute();
    $logId = (int) $logPdo->lastInsertId();

    $proposed = 0;
    $errors = [];
    try {
        $discovery = discover_new_seeds();
        $proposed = $discovery['proposed'];
        $errors = $discovery['errors'];
    } catch (Throwable $e) {
        $errors[] = 'FATAL: ' . $e->getMessage();
    } finally {
        release_run_lock('discovery');
    }

    db()->prepare(
        'UPDATE harvest_log SET finished_at = NOW(), new_seeds_discovered = ?, errors = ?, detail = ? WHERE id = ?'
    )->execute([
        $proposed, count_real_errors($errors), implode("\n", $errors), $logId,
    ]);

    return [
        'new_seeds_discovered' => $proposed,
        'errors' => $errors,
    ];
}

// ---- Monitoring: hourly email digest -------------------------------------

/**
 * A run counts as "stuck" if it's been unfinished for longer than any real
 * run should ever take — set_time_limit(240) in harvest.php means a normal
 * run can't legitimately still be going after this long, so past this
 * threshold it's a crash that somehow still left finished_at NULL, not a
 * slow-but-healthy run.
 */
const MONITOR_STUCK_THRESHOLD_MINUTES = 15;

function build_monitor_report(): array {
    $summary = get_catalog_summary();
    $recentRuns = db()->query(
        'SELECT * FROM harvest_log ORDER BY started_at DESC LIMIT 10'
    )->fetchAll();
    $stuckRuns = db()->query(
        "SELECT * FROM harvest_log WHERE finished_at IS NULL
         AND started_at < DATE_SUB(NOW(), INTERVAL " . MONITOR_STUCK_THRESHOLD_MINUTES . " MINUTE)
         ORDER BY started_at DESC"
    )->fetchAll();

    // No harvest run at all in the last 2 hours means the cron job itself
    // likely isn't firing (misconfigured path, disabled, host issue) —
    // catches a failure mode no per-run check inside the script can see.
    $lastHarvest = db()->query(
        "SELECT started_at FROM harvest_log WHERE run_type = 'harvest' ORDER BY started_at DESC LIMIT 1"
    )->fetchColumn();
    $harvestCronLikelyDown = !$lastHarvest || strtotime($lastHarvest) < strtotime('-2 hours');

    $lines = [];
    $lines[] = "ResHub status digest — " . date('Y-m-d H:i:s') . ' UTC';
    $lines[] = str_repeat('=', 60);
    $lines[] = '';
    $lines[] = "Catalog: {$summary['total_items']} items, {$summary['total_tags']} tags, {$summary['total_sources']} sources";
    $lines[] = '';

    if ($stuckRuns) {
        $lines[] = "⚠ STUCK RUNS (unfinished for over " . MONITOR_STUCK_THRESHOLD_MINUTES . " min):";
        foreach ($stuckRuns as $r) {
            $lines[] = "  - #{$r['id']} {$r['run_type']} started {$r['started_at']}, never finished";
        }
        $lines[] = '';
    }

    if ($harvestCronLikelyDown) {
        $lines[] = "⚠ NO HARVEST RUN IN OVER 2 HOURS (last: " . ($lastHarvest ?: 'never') . ") — check the mPanel cron job is still active.";
        $lines[] = '';
    }

    $lines[] = 'Last 10 runs:';
    foreach ($recentRuns as $r) {
        $status = $r['finished_at'] === null ? 'UNFINISHED' : 'ok';
        $lines[] = sprintf(
            '  #%d %-9s %s -> %s [%s] items=%d errors=%d',
            $r['id'], $r['run_type'], $r['started_at'],
            $r['finished_at'] ?? '?', $status, $r['items_added'], $r['errors']
        );
        if ($r['errors'] > 0 && $r['detail']) {
            $firstLine = strtok($r['detail'], "\n");
            $lines[] = "      -> {$firstLine}";
        }
    }

    $hasProblem = (bool) $stuckRuns || $harvestCronLikelyDown;
    $subject = ($hasProblem ? '[ATTENTION] ' : '') . "ResHub status — {$summary['total_items']} items";

    return ['subject' => $subject, 'body' => implode("\n", $lines), 'has_problem' => $hasProblem];
}

/**
 * Sends via the real configured mailbox (CONTACT_EMAIL), not a fabricated
 * "noreply@" address that likely doesn't exist as an actual mailbox on
 * this account — and critically, sets the same address as the SMTP
 * envelope sender (mail()'s 5th param, -f) explicitly. Without it,
 * sendmail falls back to a default derived from the server's own
 * hostname (confirmed via gethostname() to be an unrelated shared-hosting
 * node name, not this site's domain), which fails SPF outright for every
 * domain and gets silently dropped by Gmail — no bounce, nothing.
 */
function send_email(string $to, string $subject, string $body): bool {
    $from = defined('CONTACT_EMAIL') && CONTACT_EMAIL !== 'you@example.com' ? CONTACT_EMAIL : null;
    if (!$from) return false;
    $headers = "From: ResHub <{$from}>\r\nContent-Type: text/plain; charset=UTF-8";
    return mail($to, $subject, $body, $headers, '-f' . $from);
}

function send_monitor_report(): bool {
    if (!defined('MONITOR_EMAIL') || !MONITOR_EMAIL) {
        return false;
    }
    $report = build_monitor_report();
    return send_email(MONITOR_EMAIL, $report['subject'], $report['body']);
}

const MONITOR_WINDOW_HOURS = 8;

/**
 * Self-expiring hourly digest, callable from any cron-invoked entrypoint —
 * doesn't need its own cron job, just needs to be called once per
 * invocation of whatever already runs hourly (harvest.php). Window opens
 * on first call, tracked via settings so it survives across invocations;
 * closes automatically after MONITOR_WINDOW_HOURS with exactly one final
 * "monitoring stopped" email.
 */
function run_monitor_check(): array {
    $expiresAt = get_setting('monitor_expires_at');
    if (!$expiresAt) {
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . MONITOR_WINDOW_HOURS . ' hours'));
        set_setting('monitor_expires_at', $expiresAt);
    }

    $windowOpen = time() < strtotime($expiresAt);
    $alreadyNotifiedClose = get_setting('monitor_close_notified') === '1';

    $sent = false;
    if ($windowOpen) {
        $sent = send_monitor_report();
    } elseif (!$alreadyNotifiedClose) {
        if (defined('MONITOR_EMAIL') && MONITOR_EMAIL) {
            $body = "The " . MONITOR_WINDOW_HOURS . "-hour monitoring window (started around "
                . date('Y-m-d H:i:s', strtotime($expiresAt) - MONITOR_WINDOW_HOURS * 3600)
                . ") has ended. No further automated status emails will be sent.\n\n"
                . "This is expected, not an error. To resume monitoring, clear the "
                . "'monitor_expires_at' setting or ask for it to be restarted.";
            $sent = send_email(MONITOR_EMAIL, 'ResHub monitoring window ended', $body);
        }
        set_setting('monitor_close_notified', '1');
    }

    return ['sent' => $sent, 'window_open' => $windowOpen, 'expires_at' => $expiresAt];
}

// ---- Feedback email -> GitHub issue ---------------------------------------
//
// Rides along on the harvest cron the same way run_monitor_check() does —
// no separate cron entry needed — but only actually checks the mailbox once
// per day (the first cron tick after midnight UTC), not every 15-minute
// slot; a daily poll is plenty for feedback email and cuts down on IMAP
// connections. Silently no-ops if either FEEDBACK_IMAP_HOST or GITHUB_TOKEN
// isn't configured. Auto-created issues are labeled 'email-submission'
// deliberately, not treated as pre-vetted: anyone who knows the feedback
// address can get an issue created on the public repo with whatever they
// send, with no human review before it's public — the label exists so
// these are easy to spot and triage, not to imply they've already been
// checked.

function process_feedback_emails(): array {
    if (!defined('FEEDBACK_IMAP_HOST') || !FEEDBACK_IMAP_HOST || !defined('GITHUB_TOKEN') || !GITHUB_TOKEN) {
        return ['created' => 0]; // not configured — silently skip, not an error
    }

    $today = date('Y-m-d');
    if (get_setting('feedback_email_last_checked_date') === $today) {
        return ['created' => 0]; // already checked today
    }

    // Logged to harvest_log from here on — this is a real attempt, not a
    // no-op, so it should show up in the admin run history same as
    // harvest/discovery runs (items_added repurposed as "issues created").
    $logPdo = db();
    $logPdo->prepare("INSERT INTO harvest_log (started_at, run_type) VALUES (NOW(), 'feedback')")->execute();
    $logId = (int) $logPdo->lastInsertId();

    $port = defined('FEEDBACK_IMAP_PORT') && FEEDBACK_IMAP_PORT ? (int)FEEDBACK_IMAP_PORT : 993;
    $mailbox = '{' . FEEDBACK_IMAP_HOST . ':' . $port . '/imap/ssl}INBOX';
    $conn = @imap_open($mailbox, FEEDBACK_IMAP_USER, FEEDBACK_IMAP_PASSWORD);
    if (!$conn) {
        // Deliberately not marking today as checked — a transient
        // connection failure should retry on the next 15-minute tick, not
        // silently skip the whole day.
        $connError = 'IMAP connection failed: ' . imap_last_error();
        db()->prepare('UPDATE harvest_log SET finished_at = NOW(), errors = 1, detail = ? WHERE id = ?')
            ->execute([$connError, $logId]);
        return ['created' => 0, 'errors' => [$connError]];
    }
    set_setting('feedback_email_last_checked_date', $today);

    $created = 0;
    $errors = [];
    $emailNums = imap_search($conn, 'UNSEEN') ?: [];

    foreach ($emailNums as $num) {
        $header = imap_headerinfo($conn, $num);
        $subject = isset($header->subject) ? imap_utf8($header->subject) : '(no subject)';
        $fromPart = $header->from[0] ?? null;
        $fromAddr = $fromPart ? ($fromPart->mailbox . '@' . $fromPart->host) : 'unknown sender';

        $body = trim((string) imap_fetchbody($conn, $num, '1'));
        if ($body === '') {
            $body = trim((string) imap_body($conn, $num)); // non-multipart fallback
        }
        $body = trim(quoted_printable_decode($body));
        $body = mb_strimwidth($body, 0, 5000, "\n…(truncated)");

        $issueTitle = mb_strimwidth("[Email] {$subject}", 0, 250, '');
        $issueBody = "Submitted via email from {$fromAddr}\n\n---\n\n" . ($body !== '' ? $body : '(empty message body)');

        $ch = curl_init('https://api.github.com/repos/' . GITHUB_REPO . '/issues');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . GITHUB_TOKEN,
                'Accept: application/vnd.github+json',
                'User-Agent: ResHub-Feedback-Bot',
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'title' => $issueTitle,
                'body' => $issueBody,
                'labels' => ['email-submission'],
            ]),
        ]);
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code >= 200 && $code < 300) {
            imap_setflag_full($conn, (string)$num, '\\Seen');
            $created++;
        } else {
            $errors[] = "Failed to create issue for email #{$num} (\"{$subject}\"): HTTP {$code} " . substr((string)$response, 0, 200);
        }
    }

    imap_close($conn);

    db()->prepare('UPDATE harvest_log SET finished_at = NOW(), items_added = ?, errors = ?, detail = ? WHERE id = ?')
        ->execute([$created, count($errors), implode("\n", $errors), $logId]);

    return ['created' => $created, 'errors' => $errors];
}
