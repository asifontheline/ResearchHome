# ResHub (Research Hub) — Design Document

## 1. Goal

A self-updating catalog of freely available research (papers, patents, articles,
reports — anything with a URL) that requires no manual data entry. A scheduled
harvester discovers content, classifies it by subject and type, and publishes
it to a browsable, searchable, tag-filterable site. Items are metadata + a
link to the original source — never a copy of the content.

Runs on plain PHP + MySQL on ordinary cPanel shared hosting (MilesWeb
Premium). No daemon, no persistent process — everything is either a page
request or a short-lived cron invocation.

## 2. Non-goals

- **Not** an unrestricted web crawler. "Crawl the entire internet" is not
  feasible on shared hosting (no persistent worker, PHP execution-time
  limits, no large-scale URL frontier storage) and not responsible (ignoring
  `robots.txt` / rate limits at scale gets hosts IP-banned and looks like a
  DoS to the sites being hit). See §4.2 for the bounded version actually
  built.
- Not a content mirror. We never store full article/paper text, only
  metadata (title, authors, abstract, published date) plus the outbound URL.
- Not multi-tenant. Single shared admin login; this is a personal catalog.

## 3. Current architecture (as of this doc)

```
Browsing/search:  index.php, item.php                (public)
Manual entry:     add.php, edit.php, delete.php       (admin only, now a fallback path)
Auth:             login.php, logout.php, setup.php    (single admin user)
Harvester:        harvest.php (new)                   (cron + admin-triggered)
Shared code:      includes/{db,auth,functions,subjects,harvester}.php
```

Data flow after this epic: `harvest.php` (cron, hourly) → `includes/harvester.php`
→ writes rows into `items`/`tags`/`item_tags` → `index.php`/`item.php` read
them like any other row. The browse/search UI does not change; it was
already harvester-agnostic by design (reads from `items`, filters by `tags`).

## 4. Harvester design

### 4.1 API harvest (primary discovery mechanism)

`includes/subjects.php` is a *seed* list (30+ entries spanning sciences,
social sciences, humanities — not a hard ceiling on possible tags, see
below) of slug → label → keyword-list, used to drive keyword queries
against free structured APIs:

| Source           | Endpoint                              | Auth       | Covers |
|------------------|-----------------------------------------|------------|--------|
| arXiv            | `export.arxiv.org/api/query`           | none       | CS/physics/math/bio preprints |
| Crossref         | `api.crossref.org/works?query=...`     | none       | Journal articles, any publisher, by DOI |
| PubMed           | NCBI `esearch` + `esummary`            | optional key, higher rate limit | Medicine/life sciences |
| OpenAlex         | `api.openalex.org/works?search=...`    | none       | ~250M works, every field — broadest single source, books/datasets/preprints Crossref misses |
| Semantic Scholar | `api.semanticscholar.org/graph/v1/...` | optional key (unauth pool is shared globally and 429s often) | Strong CS/AI/bio, citation graph |
| PatentsView      | `search.patentsview.org/api/v1/patent/`| required key (free, self-serve) | US patents — silently no-ops until a key is set |

**Per-source cooldown**: each source function is called at most once per
hour regardless of cron cadence or repeated "Run harvest now" clicks — a
timestamp per source persists in `settings` (`source_ready()` /
`mark_source_called()` in `harvester.php`), and a source still on cooldown
is skipped (logged, not treated as an error). This is independent of the
subject-rotation cooldown in §4.3 below.

Each result is deduped by `url_hash` (`sha256(url)`, unique-indexed — see
§5). **Tagging is not capped at the seed list**: arXiv results are also
tagged with the paper's own declared category codes straight from the API
(arXiv's real taxonomy — `cs.LG`, `q-bio.NC`, `astro-ph.CO`, ~155 codes
total), Crossref results with Crossref's own subject-area strings when
present, and every result additionally with whatever `classify_subjects()`
matches against title+abstract. No generic `paper`/`article`/`patent` type
tag is added — every tag is either a real subject or a source's own
classification, so the tag list grows with the data rather than staying
fixed at whatever we hardcoded.

Each cron run only queries a *rotating subset* of subjects (default 1 of
30+, cursor persisted in the `settings` table) rather than the whole list —
see §4.3 for why.

**On "discovering new sources"**: this project deliberately does not attempt
to autonomously discover unknown APIs — that's not something that can be
built responsibly (see §2). What it does do: the crawler (§4.2) follows
links from known research entry points onto domains it's never touched
before. Every harvest run reports `new_hosts_discovered` — the number of
distinct new domains found this way — as an honest measure of organic
source growth over time, without pretending to be more than link-following
from a curated starting set.

This alone satisfies "different subjects and their reachable links,
categorized automatically" for the large majority of freely available
research, with no scraping risk at all.

### 4.2 Bounded crawler (supplementary, the actual "crawler" piece)

For content types APIs don't cover well (blog posts, reports, patent
listing pages), a conservative single-hop crawler:

1. **Seeds** (`seed_urls` table, managed via `seeds.php` admin page): hub /
   listing pages, e.g. an arXiv category listing, a topic RSS feed, a
   patents search results page. Configuration, not manual item entry.
2. **Politeness gate** (`hosts` table): before touching a host, fetch and
   cache its `robots.txt`; skip entirely if `/` is disallowed for `*`.
   Enforce a minimum delay between requests to the same host
   (`crawl_delay_seconds`, default 5s, or the host's own `Crawl-delay` if
   larger).
3. **Discovery**: fetch a due seed hub page, extract outbound `<a href>`
   links, enqueue new ones into `crawl_queue` (deduped by `url_hash`).
4. **Fetch**: each cron run processes a small batch (default 20) of pending
   queue rows — fetch the page, extract metadata via the existing
   `fetch_generic()` (Open Graph / meta tags), classify subject via
   `classify_subjects()` against title+abstract, insert as an item, mark
   the queue row `done`.
5. **Depth is fixed at 1** (hub → linked page). No recursive crawling —
   this bounds the crawl to "what the seed pages point at" rather than an
   open-ended walk, which is what keeps this both fast and non-abusive.

### 4.2.1 Source-discovery crawler (feeds the crawler above, doesn't run it)

A separate, narrower job from §4.2: instead of following links to find
*items*, it finds new *seeds* — new hub/listing pages to add to `seed_urls`
for the crawler above to eventually work from. `discover_new_seeds()` in
`harvester.php`, two mechanisms:

1. **`discover_sources_openalex()`** — the primary mechanism. Queries
   OpenAlex's own curated Sources index (`api.openalex.org/sources`,
   ~250k journals/repositories/preprint servers, free, no key) for
   established sources (`works_count > 5000`) we don't already have a seed
   on. This is *vetted* discovery — every proposal is a real, well-known
   repository (Zenodo, SSRN, HAL, DOAJ, RePEc, ...), not a guess. Rotates
   through source types (`repository`, `journal`) across runs; own 24-hour
   cooldown via the same `source_ready()`/`mark_source_called()` mechanism
   as the content sources in §4.1, since new sources don't appear often
   enough to need hourly checking.
2. **`maybe_flag_hub_candidate()`** — the organic complement, called from
   inside `process_queue_batch()` (§4.2 step 4) since it reuses the page
   body already fetched for metadata, no extra request. If a page's host
   was never in the `hosts` table before this run *and* the page itself
   contains ≥8 distinct outbound hosts (`HUB_CANDIDATE_MIN_DISTINCT_HOSTS`
   in `harvester.php`), it looks like a listing/index page rather than a
   single piece of content — proposed as a seed.

**Both mechanisms only propose** (`propose_seed()`: `INSERT ... active=0,
discovered=1`) — nothing here is crawled until approved in `seeds.php`'s
"Pending review" section. This is the deliberate human-in-the-loop control
mentioned in §2: broader coverage comes from *finding* legitimate sources
faster, not from trusting arbitrary new domains automatically.

### 4.3 Scheduling

`harvest.php` is invoked by a cPanel Cron Job (e.g. hourly):
```
php /home/USER/public_html/researchhome/harvest.php
```
It also has an admin-triggered web path (`POST` from a "Run harvest now"
button, login-gated) for on-demand runs during setup/testing, sharing the
same underlying functions. Each run writes one row to `harvest_log`
(items added, links discovered, errors) so activity is visible without
digging through server logs.

**Why rotation, not "query everything every run":** measured locally, a
full pass over 30+ subjects × 3 APIs took 90+ seconds — comfortably over
the ~30s `max_execution_time` many shared-hosting cPanel plans enforce
regardless of `set_time_limit()` in the script. `run_api_harvest()`
processes a rotating batch of 3 subjects per run (cursor persisted in
`settings`), which brought a real run down to ~17-26s locally. Full
coverage of the seed list happens over several cron ticks instead of one —
an explicit trade of latency-to-full-coverage for per-run reliability,
appropriate for a background pipeline nobody is watching in real time.

## 5. Indexing / search-performance plan

The browse/search UI (`index.php`) already does two things per request:
a MySQL `FULLTEXT` search (`MATCH ... AGAINST`) and a tag-scoped `JOIN`.
Once the harvester is running unattended, row counts grow continuously and
unpredictably, so indexing needs to be deliberate rather than default.

**Indexes added in this epic:**

| Table         | Index                          | Why |
|---------------|----------------------------------|-----|
| `items`       | `UNIQUE(url_hash)`              | O(1) dedup check on every harvest insert (was a missing constraint — the manual-entry-only version never needed it since duplicates were a human's problem) |
| `items`       | `FULLTEXT(title, authors, abstract, notes)` | already existed; keeps text search off `LIKE '%...%'` table scans |
| `items`       | implicit `PRIMARY(id)`, sort by `added_at` | add explicit `KEY idx_added_at (added_at)` — the homepage always sorts `ORDER BY added_at DESC`, and without this index that sort has to touch every row once volume grows past what fits in the buffer pool |
| `item_tags`   | `KEY idx_tag_id (tag_id)`        | the compound `PRIMARY(item_id, tag_id)` only serves lookups starting from `item_id`; tag-filtered browsing (`WHERE tag_id = ?`) needs `tag_id` leading a key or it falls back to a scan |
| `crawl_queue` | `UNIQUE(url_hash)`, `KEY(status)` | dedup on enqueue; cron batch pop filters `WHERE status = 'pending'` every run |
| `hosts`       | `PRIMARY(host)`                  | politeness/rate-limit lookup is always by host, once per candidate URL |

**"Sub-indexes" per category** — the literal ask: rather than one giant
`FULLTEXT` index that a category-scoped search has to filter *after*
matching, the tag join should narrow the row set *before* the `MATCH`
runs. MySQL's optimizer handles `... JOIN item_tags ... WHERE tag_id = ? AND
MATCH(...) AGAINST (?)` reasonably by using the tag index to build a small
candidate set first, so a literal separate `FULLTEXT` index per subject
isn't necessary at this scale (MySQL doesn't support partial/filtered
`FULLTEXT` indexes anyway — the whole column is always indexed). This is
revisited in §7 if volume outgrows it.

**Explicitly deferred** (not needed at current scale, revisit if/when the
threshold in §7 is hit): denormalized per-tag count caching beyond the
existing `GROUP BY` in `all_tags_with_counts()`, and any move off MySQL
`FULLTEXT` to a dedicated search engine (Meilisearch/Typesense) — those
need a persistent service, which shared hosting can't run.

## 6. Data model additions

```
hosts            host (PK), robots_rules, robots_fetched_at,
                  crawl_delay_seconds, last_crawled_at, disallowed
seed_urls         id, url, subject_slug, active, added_at, last_crawled_at
crawl_queue       id, url, url_hash (unique), host, subject_slug,
                  status(pending/done/skipped/error), discovered_at,
                  processed_at, error
harvest_log       id, started_at, finished_at, items_added,
                  links_discovered, errors, detail
items             + url_hash CHAR(64) UNIQUE, + idx_added_at
item_tags         + idx_tag_id
```

Full DDL lives in `sql/schema.sql`.

## 7. Rollout plan

1. **Schema migration** — add the new tables/indexes (done via updated
   `sql/schema.sql`; safe to re-import, all `CREATE TABLE IF NOT EXISTS`).
2. **Harvester engine** (`includes/harvester.php`) — API harvest functions,
   robots.txt-gated crawl functions, `run_harvest()` orchestrator.
3. **Entrypoint** (`harvest.php`) — CLI-safe, plus an admin "run now" button.
4. **Admin visibility** — `seeds.php` (manage hub URLs), `harvest_log.php`
   (run history), so the unattended pipeline stays inspectable.
5. **Local end-to-end test** against the real internet (arXiv/Crossref/
   PubMed calls, one real hub page) before calling it shippable.
6. **Deploy**: import schema, set `APP_SECRET`/DB creds, add a handful of
   seed URLs, add the cPanel cron job, watch the first few `harvest_log`
   rows.

**Revisit threshold**: if `items` grows past roughly 200k–500k rows (the
point where MySQL `FULLTEXT` on shared-hosting-grade hardware starts
costing noticeably more per query) or a single harvest run can't finish
inside the host's `max_execution_time`, revisit — likely by lowering the
per-run batch size and increasing cron frequency first, before reaching
for infrastructure shared hosting can't provide.

## 8. Risks / open questions

- **Classification quality**: keyword-matching title/abstract against a
  fixed subject list is simple and will mis-tag or under-tag edge cases.
  Acceptable for v1; the tag list is just data (`includes/subjects.php`),
  easy to tune.
- **Seed curation is manual**: the crawler only goes where seeds point it.
  This is intentional (bounded scope) but means crawl coverage is only as
  good as the seed list — worth revisiting periodically via `seeds.php`.
- **PubMed rate limits** without an API key are low (3 req/s); fine for
  hourly cron batches, would need `NCBI_API_KEY` set if harvest frequency
  increases.
- **cPanel cron availability**: confirmed as standard on the MilesWeb
  Premium plan (cPanel → Cron Jobs).
