<?php
require_once __DIR__ . '/functions.php';

// ---- Politeness: robots.txt + per-host rate limiting ---------------------

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
        $arxivCategories = [];
        foreach ($entry->category as $cat) {
            $term = (string) $cat['term'];
            if ($term !== '') $arxivCategories[] = $term;
        }

        $tags = array_unique(array_merge($arxivCategories, classify_subjects($title . ' ' . $abstract), [$subjectSlug]));
        $id = insert_item_if_new([
            'title' => $title, 'url' => $url,
            'authors' => implode(', ', $authors), 'abstract' => $abstract,
            'source_name' => 'arXiv',
            'published_date' => date('Y-m-d', strtotime((string)$entry->published)),
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
        $tags = array_unique(array_merge($crossrefSubjects, classify_subjects($title . ' ' . $abstract), [$subjectSlug]));

        $id = insert_item_if_new([
            'title' => $title, 'url' => $url,
            'authors' => implode(', ', array_filter($authors)),
            'abstract' => $abstract ?: null,
            'source_name' => $msg['publisher'] ?? 'Crossref',
            'published_date' => $published,
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

    $added = 0;
    foreach ($ids as $pmid) {
        $meta = fetch_pubmed($pmid);
        if (!$meta || !$meta['title']) continue;
        $url = "https://pubmed.ncbi.nlm.nih.gov/{$pmid}/";
        $tags = array_unique(array_merge(classify_subjects($meta['title']), [$subjectSlug]));
        $id = insert_item_if_new([
            'title' => $meta['title'], 'url' => $url,
            'authors' => $meta['authors'], 'abstract' => $meta['abstract'],
            'source_name' => 'PubMed', 'published_date' => $meta['published_date'],
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

        $tags = array_unique(array_merge(array_filter($topics), classify_subjects($title . ' ' . ($abstract ?? '')), [$subjectSlug]));

        $id = insert_item_if_new([
            'title' => $title, 'url' => $url,
            'authors' => implode(', ', array_filter($authors)),
            'abstract' => $abstract,
            'source_name' => $work['primary_location']['source']['display_name'] ?? 'OpenAlex',
            'published_date' => $work['publication_date'] ?? null,
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
    $fields = 'title,abstract,authors,year,externalIds,openAccessPdf,url,fieldsOfStudy';
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

        $tags = array_unique(array_merge($fieldsOfStudy, classify_subjects($title . ' ' . ($paper['abstract'] ?? '')), [$subjectSlug]));

        $id = insert_item_if_new([
            'title' => $title, 'url' => $url,
            'authors' => implode(', ', array_filter($authors)),
            'abstract' => $paper['abstract'] ?? null,
            'source_name' => 'Semantic Scholar',
            'published_date' => isset($paper['year']) ? "{$paper['year']}-01-01" : null,
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
        $tags = array_unique(array_merge(classify_subjects($title . ' ' . ($p['patent_abstract'] ?? '')), [$subjectSlug]));

        $id = insert_item_if_new([
            'title' => $title,
            'url' => "https://patents.google.com/patent/US{$patentId}",
            'authors' => implode(', ', array_filter($inventors)),
            'abstract' => $p['patent_abstract'] ?? null,
            'source_name' => 'USPTO',
            'published_date' => $p['patent_date'] ?? null,
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

function run_api_harvest(array $subjects, int $perSourceMax = 5, int $subjectsPerRun = 1): array {
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
    foreach ($batch as $slug) {
        $keyword = $subjects[$slug]['keywords'][0];
        foreach ($sources as $fn) {
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
    return ['added' => $added, 'errors' => $errors, 'subjects' => $batch, 'skipped' => array_unique($skipped)];
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

function crawl_due_seeds(int $limit = 3): array {
    $stmt = db()->query(
        "SELECT * FROM seed_urls WHERE active = 1
         ORDER BY (last_crawled_at IS NULL) DESC, last_crawled_at ASC
         LIMIT " . (int)$limit
    );
    $seeds = $stmt->fetchAll();

    $discovered = 0;
    $errors = [];
    foreach ($seeds as $seed) {
        try {
            if (!can_crawl_url($seed['url'])) continue;
            $host = parse_url($seed['url'], PHP_URL_HOST);

            $body = safe_http_get($seed['url'], ["User-Agent: " . HARVEST_USER_AGENT]);
            mark_host_crawled($host);

            if (!$body) {
                $failures = (int) $seed['failed_fetches'] + 1;
                if ($failures >= SEED_FAILURE_THRESHOLD) {
                    db()->prepare('UPDATE seed_urls SET last_crawled_at = NOW(), failed_fetches = ?, active = 0 WHERE id = ?')
                        ->execute([$failures, $seed['id']]);
                    $errors[] = "seed {$seed['id']} ({$seed['url']}) fetch failed {$failures}x — auto-disabled (likely bot-protected or unreachable; re-enable in seeds.php to retry)";
                } else {
                    db()->prepare('UPDATE seed_urls SET last_crawled_at = NOW(), failed_fetches = ? WHERE id = ?')
                        ->execute([$failures, $seed['id']]);
                    $errors[] = "seed {$seed['id']} ({$seed['url']}) fetch failed ({$failures}/" . SEED_FAILURE_THRESHOLD . ")";
                }
                continue;
            }

            db()->prepare('UPDATE seed_urls SET last_crawled_at = NOW(), failed_fetches = 0 WHERE id = ?')->execute([$seed['id']]);

            $links = extract_links($body, $seed['url']);
            foreach ($links as $link) {
                $hash = url_hash($link['url']);
                $linkHost = parse_url($link['url'], PHP_URL_HOST);
                if (!$linkHost) continue;
                try {
                    db()->prepare(
                        'INSERT IGNORE INTO crawl_queue (url, url_hash, host, subject_slug) VALUES (?, ?, ?, ?)'
                    )->execute([$link['url'], $hash, $linkHost, $seed['subject_slug']]);
                    $discovered++;
                } catch (Throwable $e) {
                    // duplicate or malformed, skip
                }
            }
        } catch (Throwable $e) {
            // A dead connection (e.g. "MySQL server has gone away" after a
            // slow fetch above) or any other failure here must not crash the
            // whole run — reconnect so the next seed gets a working
            // connection, log it, and move on.
            db(true);
            $errors[] = "seed {$seed['id']} ({$seed['url']}): " . $e->getMessage();
        }
    }
    return ['discovered' => $discovered, 'errors' => $errors];
}

function host_known_before_this_run(string $host): bool {
    $stmt = db()->prepare('SELECT 1 FROM hosts WHERE host = ? LIMIT 1');
    $stmt->execute([$host]);
    return (bool) $stmt->fetchColumn();
}

function process_queue_batch(int $limit = 20): array {
    $stmt = db()->query(
        "SELECT * FROM crawl_queue WHERE status = 'pending' ORDER BY discovered_at ASC LIMIT " . (int)$limit
    );
    $rows = $stmt->fetchAll();

    $added = 0;
    $errors = 0;
    foreach ($rows as $row) {
        try {
            // Checked before can_crawl_url() touches the hosts table, so it
            // reflects whether we'd ever seen this host prior to this run.
            $hostIsNew = !host_known_before_this_run($row['host']);

            if (!can_crawl_url($row['url'])) {
                continue; // leave pending, try again on a later run once the host cools down
            }

            $body = safe_http_get($row['url'], ['User-Agent: ' . HARVEST_USER_AGENT]);
            mark_host_crawled($row['host']);

            if (!$body) {
                db()->prepare("UPDATE crawl_queue SET status='skipped', processed_at=NOW() WHERE id=?")->execute([$row['id']]);
                continue;
            }

            $meta = extract_generic_metadata($body, $row['url']);
            maybe_flag_hub_candidate($row['url'], $body, $hostIsNew);

            if (!$meta['title']) {
                db()->prepare("UPDATE crawl_queue SET status='skipped', processed_at=NOW() WHERE id=?")->execute([$row['id']]);
                continue;
            }

            $subjects = array_filter([$row['subject_slug']]);
            $subjects = array_unique(array_merge($subjects, classify_subjects($meta['title'] . ' ' . ($meta['abstract'] ?? ''))));

            $id = insert_item_if_new([
                'title' => $meta['title'], 'url' => $row['url'],
                'authors' => $meta['authors'], 'abstract' => $meta['abstract'],
                'source_name' => $meta['source_name'], 'published_date' => $meta['published_date'],
                'image_url' => $meta['image_url'],
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
 * A single failed check can be transient (server hiccup, timeout), so items
 * aren't removed on the first failure. HTTP responses that unambiguously
 * mean "this no longer exists" (404/410) count immediately toward removal;
 * everything else (timeouts, 5xx, connection errors) only counts after
 * repeating on a later run, which is what the failed_checks counter is for.
 */
const LINK_FAILURE_THRESHOLD = 3;

function check_links_batch(int $limit = 8): array {
    $rows = db()->query(
        "SELECT id, url, failed_checks FROM items
         WHERE last_checked_at IS NULL OR last_checked_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
         ORDER BY last_checked_at IS NOT NULL, last_checked_at ASC
         LIMIT " . (int)$limit
    )->fetchAll();

    $checked = 0;
    $removed = 0;
    foreach ($rows as $row) {
        $code = check_url_status($row['url']);
        $checked++;

        $isDefinitelyGone = in_array($code, [404, 410], true);
        $isUnreachable = $code === null || $code >= 400;

        if ($code !== null && $code < 400) {
            db()->prepare('UPDATE items SET last_checked_at = NOW(), failed_checks = 0 WHERE id = ?')
                ->execute([$row['id']]);
            continue;
        }

        $failures = (int)$row['failed_checks'] + 1;
        if ($isDefinitelyGone || $failures >= LINK_FAILURE_THRESHOLD) {
            db()->prepare('DELETE FROM items WHERE id = ?')->execute([$row['id']]);
            $removed++;
        } elseif ($isUnreachable) {
            db()->prepare('UPDATE items SET last_checked_at = NOW(), failed_checks = ? WHERE id = ?')
                ->execute([$failures, $row['id']]);
        }
    }

    return ['checked' => $checked, 'removed' => $removed];
}

// ---- Orchestrator -------------------------------------------------------

/**
 * Content harvest: API sources + crawl + link-health. Meant to run
 * frequently (harvest.php on its own cron entry) — everything in here is
 * already internally cooldown-gated per source, so frequent invocations
 * mostly no-op cheaply rather than doing redundant work.
 */
function run_content_harvest(): array {
    $subjects = require __DIR__ . '/subjects.php';
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
    // instead of one stuck showing "running…" forever.
    try {
        $hostsBefore = (int) db()->query('SELECT COUNT(*) FROM hosts')->fetchColumn();

        $api = run_api_harvest($subjects);
        $itemsAdded += $api['added'];
        $errors = array_merge($errors, $api['errors']);
        if ($api['skipped']) {
            $errors[] = 'Skipped (called within the last hour): ' . implode(', ', $api['skipped']);
        }

        $seeds = crawl_due_seeds();
        $linksDiscovered = $seeds['discovered'];
        $errors = array_merge($errors, $seeds['errors']);

        $queue = process_queue_batch();
        $itemsAdded += $queue['added'];
        $queueErrors = $queue['errors'];

        $linkCheck = check_links_batch();
        $linksChecked = $linkCheck['checked'];
        $itemsRemoved = $linkCheck['removed'];

        // Every new row in `hosts` since this run started is a domain the
        // crawler had never touched before — a concrete, honest measure of
        // "new sources found on the internet" via link-following from known
        // research entry points (not a claim of discovering unknown APIs).
        $hostsAfter = (int) db()->query('SELECT COUNT(*) FROM hosts')->fetchColumn();
        $newHostsDiscovered = max(0, $hostsAfter - $hostsBefore);
    } catch (Throwable $e) {
        $errors[] = 'FATAL: ' . $e->getMessage();
    }

    db()->prepare(
        'UPDATE harvest_log
         SET finished_at = NOW(), items_added = ?, links_discovered = ?, links_checked = ?,
             items_removed = ?, new_hosts_discovered = ?, errors = ?, detail = ?
         WHERE id = ?'
    )->execute([
        $itemsAdded, $linksDiscovered, $linksChecked, $itemsRemoved,
        $newHostsDiscovered, count($errors) + $queueErrors, implode("\n", $errors), $logId,
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

/**
 * Source discovery only (§4.2.1 in DESIGN.md): proposes new seeds, doesn't
 * crawl or harvest content. Meant to run on its own, less-frequent cron
 * entry (discover.php) — decoupled from content harvest since new sources
 * don't appear often enough to need the same cadence, and internally this
 * is already gated to once per 24h regardless of how often it's invoked.
 */
function run_source_discovery(): array {
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
    }

    db()->prepare(
        'UPDATE harvest_log SET finished_at = NOW(), new_seeds_discovered = ?, errors = ?, detail = ? WHERE id = ?'
    )->execute([
        $proposed, count($errors), implode("\n", $errors), $logId,
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
    $lines[] = "ResHub status digest — " . date('Y-m-d H:i:s') . ' server time';
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

function send_monitor_report(): bool {
    if (!defined('MONITOR_EMAIL') || !MONITOR_EMAIL) {
        return false;
    }
    $report = build_monitor_report();
    $fromDomain = defined('CONTACT_EMAIL') && str_contains(CONTACT_EMAIL, '@')
        ? substr(CONTACT_EMAIL, strpos(CONTACT_EMAIL, '@') + 1)
        : 'localhost';
    $headers = "From: ResHub Monitor <noreply@{$fromDomain}>\r\nContent-Type: text/plain; charset=UTF-8";
    return mail(MONITOR_EMAIL, $report['subject'], $report['body'], $headers);
}
