-- ResearchHome schema
-- Import this via phpMyAdmin into the MySQL database created in cPanel.

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(512) NOT NULL,
    url VARCHAR(1024) NOT NULL,
    url_hash CHAR(64) NOT NULL,                 -- sha256(url), used for fast dedupe (url itself is too long to index)
    source_name VARCHAR(128) DEFAULT NULL,      -- e.g. arXiv, PubMed, Crossref, Google Patents, Web
    authors TEXT DEFAULT NULL,
    abstract TEXT DEFAULT NULL,
    notes TEXT DEFAULT NULL,                    -- personal notes, freely editable
    published_date DATE DEFAULT NULL,
    image_url VARCHAR(1024) DEFAULT NULL,
    added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_checked_at DATETIME DEFAULT NULL,      -- last time the outbound link was verified reachable
    failed_checks TINYINT UNSIGNED NOT NULL DEFAULT 0, -- consecutive failures; item is removed past a threshold
    citation_count INT UNSIGNED DEFAULT NULL,   -- from source metadata where available (OpenAlex, Semantic Scholar, Crossref); NULL where the source doesn't report one (arXiv, PubMed, patents)
    content_type ENUM('research','video') NOT NULL DEFAULT 'research', -- 'video' items (YouTube/Vimeo) live in their own section (videos.php), excluded from the main research catalog/search/credits/activity chart
    UNIQUE KEY uniq_url_hash (url_hash),
    KEY idx_added_at (added_at),
    KEY idx_last_checked_at (last_checked_at),
    KEY idx_citation_count (citation_count),
    KEY idx_content_type (content_type),
    FULLTEXT KEY ft_search (title, authors, abstract, notes)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tags (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(64) NOT NULL UNIQUE,
    slug VARCHAR(64) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS item_tags (
    item_id INT UNSIGNED NOT NULL,
    tag_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (item_id, tag_id),
    KEY idx_tag_id (tag_id),
    FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- Crawler / harvester ---------------------------------------------

-- Small key-value store for harvester state (e.g. which subject to resume from).
CREATE TABLE IF NOT EXISTS settings (
    name VARCHAR(64) NOT NULL PRIMARY KEY,
    value VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per domain we've crawled: caches robots.txt rules and enforces
-- a minimum delay between requests to the same host (politeness).
CREATE TABLE IF NOT EXISTS hosts (
    host VARCHAR(255) NOT NULL PRIMARY KEY,
    robots_rules TEXT DEFAULT NULL,             -- newline-separated Disallow paths for User-agent: * (or our UA)
    robots_fetched_at DATETIME DEFAULT NULL,
    crawl_delay_seconds INT UNSIGNED NOT NULL DEFAULT 5,
    last_crawled_at DATETIME DEFAULT NULL,
    disallowed TINYINT(1) NOT NULL DEFAULT 0    -- whole host disallows '/', skip entirely
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Hub / listing pages the crawler starts from for a given subject, e.g. an
-- arXiv category listing or a topic RSS feed. Configured via seeds.php, or
-- proposed automatically by the source-discovery crawler (see harvester.php
-- discover_new_seeds()) — those land with active=0, discovered=1, pending
-- review in seeds.php rather than being trusted automatically.
CREATE TABLE IF NOT EXISTS seed_urls (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    url VARCHAR(1024) NOT NULL,
    host VARCHAR(255) DEFAULT NULL,             -- parse_url(url, PHP_URL_HOST), for fast "already have a seed on this host" checks
    subject_slug VARCHAR(64) DEFAULT NULL,      -- NULL for discovered general-purpose sources
    active TINYINT(1) NOT NULL DEFAULT 1,
    discovered TINYINT(1) NOT NULL DEFAULT 0,   -- 1 = proposed by discover_new_seeds(), not manually added
    discovery_source VARCHAR(64) DEFAULT NULL,  -- e.g. 'openalex-sources', 'crawler-hub-heuristic'
    seed_group TINYINT UNSIGNED DEFAULT NULL,   -- 0-3, which 15-min cron slot crawls this seed (see current_seed_group() in harvester.php); assigned round-robin at add/approve time, not derived from id (id % 4 drifts uneven once seeds get deleted)
    added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_crawled_at DATETIME DEFAULT NULL,
    failed_fetches TINYINT UNSIGNED NOT NULL DEFAULT 0, -- auto-disabled past SEED_FAILURE_THRESHOLD (harvester.php)
    UNIQUE KEY uniq_url (url(255)),
    KEY idx_host (host),
    KEY idx_seed_group (seed_group)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Discovered links waiting to be visited (depth-1 from a seed hub page).
CREATE TABLE IF NOT EXISTS crawl_queue (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    url VARCHAR(1024) NOT NULL,
    url_hash CHAR(64) NOT NULL,
    host VARCHAR(255) NOT NULL,
    subject_slug VARCHAR(64) DEFAULT NULL,
    status ENUM('pending','done','skipped','error') NOT NULL DEFAULT 'pending',
    discovered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME DEFAULT NULL,
    error TEXT DEFAULT NULL,
    UNIQUE KEY uniq_url_hash (url_hash),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Lightweight, privacy-respecting page-view log (public visitors only —
-- see record_page_view() in functions.php, called from header.php). No raw
-- IPs stored: visitor_hash is sha256(ip + APP_SECRET + today's date), which
-- rotates daily so the same visitor can't be correlated across days, while
-- still allowing a same-day "unique visitors" count via COUNT(DISTINCT).
-- MVP per explicit instruction: build small, keep only if it proves useful.
CREATE TABLE IF NOT EXISTS page_views (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    path VARCHAR(255) NOT NULL,
    item_id INT UNSIGNED DEFAULT NULL,      -- populated for item.php views, for "most viewed items"
    visitor_hash CHAR(64) NOT NULL,
    referrer_host VARCHAR(255) DEFAULT NULL,
    viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_viewed_at (viewed_at),
    KEY idx_path (path),
    KEY idx_item_id (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Resolved location per visitor_hash (not per IP — the raw IP is never
-- stored, only used transiently to call the geolocation API). Keyed by the
-- same daily-rotating salted hash as page_views, so this doubles as a cache
-- (one lookup per unique visitor per day) and never links a location back
-- to a real IP address once written.
CREATE TABLE IF NOT EXISTS geo_cache (
    visitor_hash CHAR(64) PRIMARY KEY,
    country VARCHAR(100) DEFAULT NULL,
    region VARCHAR(100) DEFAULT NULL,
    city VARCHAR(100) DEFAULT NULL,
    lat DECIMAL(7,4) DEFAULT NULL,    -- city-level precision from the geo API, not exact address
    lon DECIMAL(7,4) DEFAULT NULL,
    looked_up_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Fixed-window per-IP request counter — see enforce_request_rate_limit() in
-- functions.php. Deliberately separate from geo_cache/page_views (those are
-- daily-rotating and privacy-oriented); this just needs a short-lived,
-- stable-for-a-few-seconds key to stop a burst from hammering the server.
CREATE TABLE IF NOT EXISTS request_throttle (
    throttle_key CHAR(64) PRIMARY KEY,
    window_start DATETIME NOT NULL,
    request_count INT UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A public search that returned zero results. The harvester picks these up
-- (highest search_count first) and runs them as one-off keyword searches
-- across the API sources, same as the regular subject rotation but without
-- adding the search string itself as a tag. harvested_at marks it as
-- attempted (not necessarily successful — items_found may still be 0 if
-- nothing free/open exists for that query).
CREATE TABLE IF NOT EXISTS search_misses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    query VARCHAR(255) NOT NULL,
    query_hash CHAR(64) NOT NULL,
    first_searched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_searched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    search_count INT UNSIGNED NOT NULL DEFAULT 1,
    harvested_at DATETIME DEFAULT NULL,
    items_found INT UNSIGNED DEFAULT NULL,
    UNIQUE KEY uniq_query_hash (query_hash),
    KEY idx_harvested_at (harvested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every search-bar keyword submission, hit or miss — distinct from
-- search_misses above, which exists purely to queue zero-result queries
-- for the harvester. This is for visibility: what people actually search
-- for, and what fraction comes up empty. One row per submission (not
-- deduped/counted like search_misses) since result_count can change
-- between two searches of the same term as the catalog grows.
CREATE TABLE IF NOT EXISTS search_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    query VARCHAR(255) NOT NULL,
    result_count INT UNSIGNED NOT NULL,
    searched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_searched_at (searched_at),
    KEY idx_query (query)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per harvest.php or discover.php run, for visibility since nothing
-- is added by hand.
CREATE TABLE IF NOT EXISTS harvest_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    run_type ENUM('harvest','discovery','feedback') NOT NULL DEFAULT 'harvest',
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME DEFAULT NULL,
    items_added INT UNSIGNED NOT NULL DEFAULT 0,
    links_discovered INT UNSIGNED NOT NULL DEFAULT 0,
    links_checked INT UNSIGNED NOT NULL DEFAULT 0,
    items_removed INT UNSIGNED NOT NULL DEFAULT 0,
    new_hosts_discovered INT UNSIGNED NOT NULL DEFAULT 0,
    new_seeds_discovered INT UNSIGNED NOT NULL DEFAULT 0,
    errors INT UNSIGNED NOT NULL DEFAULT 0,
    detail TEXT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
