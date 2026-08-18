# ResHub (Research Hub) — Design Document

## 1. Goal

A self-updating catalog of freely available research (papers, patents, articles,
reports — anything with a URL) that requires no manual data entry. A scheduled
harvester discovers content, classifies it by subject and type, and publishes
it to a browsable, searchable, tag-filterable site. Items are metadata + a
link to the original source — never a copy of the content.

Runs on plain PHP + MySQL on ordinary web-hosted cPanel shared hosting.
Everything is a page request or a short-lived cron invocation — harvest,
discovery, and tag/URL validation (`validator.php`, every 5 min) alike.
An earlier version ran validation as a long-lived `validator_daemon.php`
process instead, on the theory that a persistent process couldn't fall
behind under load the way a periodic batch could; in practice the host
killed it every 10-20 minutes regardless (a host-level resource limit),
making it behave like a worse-scheduled cron job anyway. See §4.5.

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

Data flow after this epic: `harvest.php` (cron, every 15 min) → `includes/harvester.php`
→ writes rows into `items`/`tags`/`item_tags` → `index.php`/`item.php` read
them like any other row. The browse/search UI does not change; it was
already harvester-agnostic by design (reads from `items`, filters by `tags`).
(Was hourly — moved to a 15-minute cadence once the seed list grew large
enough that a single run crawling all of it took several minutes; seeds are
now split into 4 rotating groups instead, one group per 15-minute slot, so
each run stays fast. See §4.3.)

## 4. Harvester design

### 4.1 API harvest (primary discovery mechanism)

The curated subject taxonomy (85 entries spanning sciences, social
sciences, humanities, arts, business, ... — not a hard ceiling on possible
tags, see below) of slug → label → keyword-list, used to drive keyword
queries against free structured APIs, lives in a `subjects` DB table, not
a static file. `includes/subjects.php` is one-time seed data only —
`ensure_subjects_table()` (`functions.php`) creates the table and imports
it from that file on first use, then never reads it again. A static file
would get silently overwritten by every `git push` deploy (the FTP action
replaces every tracked file, the same reason `config.php` is excluded from
deploy rather than editable at runtime) — an admin-added subject needs to
survive that, so it lives in the DB and is manageable live from
`subjects_admin.php` / `subject_edit.php`, no deploy required:

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
85, cursor persisted in the `settings` table) rather than the whole list —
see §4.3 for why.

#### 4.1.1 Tag quality: no false positives, no zero-tag items

`classify_subjects()` (`functions.php`) matches keywords with word-boundary
regex, not a raw substring search — a substring match let a single
generic keyword fire inside unrelated words (confirmed in production: the
`law` subject's keyword `regulation` false-positive-tagged a gut-microbiota
article "Law" via "iron regulation"; a `gene` keyword matched inside
"General Relativity"). Keyword lists lean on specific compound phrases for
the same reason (`case law` instead of bare `law`, `government regulation`
instead of bare `regulation`) — a generic single word shows up constantly
in writing that has nothing to do with the subject it's meant to signal.

Every item gets at least one tag, guaranteed structurally rather than left
to chance: `insert_item_if_new()` applies a `general` fallback subject
(empty keyword list, so `classify_subjects()` can never match it directly —
only this explicit fallback path ever applies it) whenever no source
category, seed subject, or keyword match produced anything. `general` is
not a resting state — three background passes, all random-sampled (not a
FIFO cursor, so nothing can get permanently stuck behind one; see the
`RAND()`-based query shape in `classify_zero_tag_backlog()`) and run as a
slice of every 15-minute harvest cycle, keep working the backlog down:

- `retag_backlog_batch()` reconciles every item's taxonomy tags against
  what the *current* classifier/taxonomy would assign, removing stale
  false positives and adding newly-matching ones — catches both the
  substring-bug legacy data and drift from taxonomy edits.
- `classify_zero_tag_backlog()` re-fetches a zero-tag item's page (falling
  back to a plain-text body snippet when there's no description meta tag)
  and retries classification.
- `reclassify_general_backlog()` retries items still on `general` — this
  can newly succeed as the taxonomy grows (either from a future `git push`
  or an admin using `subjects_admin.php`), independent of any change to
  the item itself.

Deleting an item, correcting a tag, or reconciling a mistag can leave a
`tags` row with zero items — there was never a code path that cleaned
that up. `prune_orphaned_tags()` (scoped to the specific tag ids a given
operation just touched, not a full-table sweep) runs at every point that
can orphan a tag — `set_item_tags()`, the new `delete_item()` wrapper used
by every item-deletion call site, and `retag_backlog_batch()`'s direct
tag removal — so cleanup happens at the same moment as the removal, not a
deferred batch job.

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
4. **Fetch**: each cron run processes a small batch (default 300) of pending
   queue rows — fetch the page, extract metadata via
   `extract_generic_metadata()` (Open Graph / meta tags, plus `<html lang>`
   or `og:locale` for language detection), classify subject via
   `classify_subjects()` against title+abstract (falling back to a
   plain-text body snippet when there's no description meta tag), insert
   as an item, mark the queue row `done`.
   Rejects a page before insertion if its title is just the site's own
   branding rather than an article headline (`looks_like_site_branding()`)
   — confirmed in production: an old, defunct PLOS domain's
   category-listing pages had plain titles like "PLOS One", no article
   behind any of them, and ~40 got inserted as fake items before this
   existed. Two signals: a short title that's essentially the host name
   with punctuation stripped, or a short title matching a small generic
   wordlist ("Home", "Journal Home", ...) independent of the host.
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
   established sources we don't already have a seed on. This is *vetted*
   discovery — every proposal is a real, well-known repository (Zenodo,
   SSRN, HAL, DOAJ, RePEc, ...), not a guess. Own 24-hour cooldown via the
   same `source_ready()`/`mark_source_called()` mechanism as the content
   sources in §4.1, since new sources don't appear often enough to need
   hourly checking.

   Rotates through `(type, geography)` combinations — `type` is
   `repository`/`journal`; `geography` is `'global'` (unfiltered,
   captures international/borderless repositories like Zenodo/SSRN that
   OpenAlex doesn't attribute to one country) plus ~61 country codes via
   OpenAlex's own `country_code` filter. Explicitly ordered and weighted
   by the world's top-10 countries by total research output (US, China,
   UK, Germany, Japan, India, France, Italy, Canada, Australia, in that
   rank order) — US/China (far ahead of everyone else) appear 3x, the
   rest of the top 10 appear 2x each, preserving their relative rank
   order — with a long tail covering the rest of the world (Southeast
   Asia, Latin America, Africa, Middle East, Eastern Europe, Oceania) at
   1x. Since combos advance via a monotonic cursor through this ordered
   array, a country's position controls both how soon it's first reached
   and how often it recurs each cycle — both reinforce priority, not just
   frequency. `OPENALEX_DISCOVERY_COMBOS_PER_RUN` (5) combos are swept
   per call — the actual throughput lever, since the 24h cooldown is
   unaffected by cron frequency. Each combo keeps its own page cursor
   (`openalex_source_page_cursor_{type}_{geography}` in `settings`),
   wrapping back to page 1 once a page returns fewer than a full page's
   worth of results — without this, `propose_seed()`'s host-dedupe means
   the exact same top-of-ranking slice gets re-fetched forever and
   nothing past page 1 is ever reached. Country-scoped queries use a much
   lower `works_count` floor (`>20`) than the global query (`>1000`) — a
   single institution's repository legitimately has far fewer total
   works than a global mega-repository. Confirmed via the live API this
   genuinely surfaces sources the un-paginated, ungeography-scoped
   original version structurally never could (Japan's NII institutional
   repository aggregator, India's Shodhganga, several Chinese university
   repositories) in a single test run.
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

### 4.2.2 Link health

Link-health checking lives in the continuous validator (§4.5), not
`harvest.php` — `check_links_batch()` reachability-checks a slice of
existing items' URLs every daemon iteration and removes ones that are
truly dead. `check_url_status()` sends `HEAD` first (cheaper), retrying
with a ranged `GET` whenever `HEAD` comes back as any kind of error — not
just a hard connection failure. That retry condition used to be narrower
(only a connection failure, `code === 0`), and it mattered: confirmed in
production, a WordPress/OpenEdition-hosted page
(`archivalia.hypotheses.org`) returned a 404 to `HEAD` while the exact same
URL returned 200 with real content on `GET`. Every failure code, 404/410
included, now goes through the same `LINK_FAILURE_THRESHOLD` (3
consecutive failures) grace period rather than 404/410 deleting on the
first check — trades a genuinely dead link lingering a little longer for
far fewer wrongful deletions of live pages that got blocked or misbehaved
once. Readers can also flag a specific link directly
(`report_broken_link.php`, no login) — it re-verifies before acting rather
than trusting the report blindly.

Which items get checked each pass isn't a random sample anymore —
`items.validation_group` (0-5), assigned round-robin at insert time,
combined with `current_validation_group()` (wall-clock minute → group,
10-minute windows) means the *entire* catalog gets a link-health pass on
a predictable ~hourly cadence, not merely "probably eventually" the way
pure `ORDER BY RAND()` sampling only statistically approached. Within a
group, oldest-`last_checked_at`-first so a group too large to fully
process in one hour's daemon iterations still converges over successive
hours rather than re-sampling the same rows. `backfill_validation_groups_batch()`
catches up items that predate this column.

**Seed failures follow the same "don't mistake transient for broken"
principle, on a longer timescale.** A seed is only crawled ~once/hour
(its own `seed_group`'s turn), so a raw attempt-count threshold alone
could disable a seed after as little as ~3 hours of being down — a
maintenance window at a bad time of day, not a genuinely broken source.
`seed_urls.first_failed_at` tracks when the current consecutive-failure
streak began; `disable_seed_after_failure()` now fires only once BOTH
`SEED_FAILURE_THRESHOLD` (3) attempts *and* `SEED_FAILURE_MIN_DAYS` (3
elapsed days) have passed (`seed_should_disable()`). Reset to `NULL`
alongside `failed_fetches` on any success, reactivation, or manual
re-enable. `SEED_PERMANENT_DISABLE_CYCLES` (7 cycles to permanent
disable, unchanged) now takes roughly a month of sustained failure
instead of ~1 week as a result, since each cycle is bounded below by 3
days instead of ~3 hours.

### 4.3 Scheduling

`harvest.php` is invoked by a cPanel Cron Job, every 15 minutes:
```
php /home/USER/public_html/researchhome/harvest.php
```
(Was hourly originally — moved to 15 minutes once the seed list grew large
enough that crawling all of it in one run took several minutes on its own.
`harvest_already_ran_this_slot()` caps actual execution to once per
15-minute window regardless of how many times cron fires, same self-healing
pattern as the old hourly cap — `HARVEST_MAX_RUNTIME_MINUTES` (14) keeps
the run-lock's timeout under the cron interval, so a crashed run's lock
clears before the next slot instead of blocking it.)

It also has an admin-triggered web path (`POST` from a "Run harvest now"
button, login-gated) for on-demand runs during setup/testing, sharing the
same underlying functions. Each run writes one row to `harvest_log`
(items added, links discovered, errors) so activity is visible without
digging through server logs.

**Seed crawling is split into 4 rotating groups**, not "every active seed,
every run" — that stopped fitting comfortably in one run once the seed list
grew. Each `seed_urls` row has a `seed_group` (0–3), assigned round-robin
via a persistent cursor (`seed_group_cursor` in `settings`) at the moment a
seed becomes active — `assign_next_seed_group()` in `harvester.php`, called
from `seeds.php`'s "add" and "approve" actions. Deliberately *not* `id % 4`:
that drifts uneven as seeds get deleted over time (confirmed in practice —
one group ended up several seeds behind the others after normal churn),
where a persistent cursor stays balanced regardless of gaps in the id
sequence. Which group crawls is purely a function of the current 15-minute
slot (`current_seed_group()`): `:15`→0, `:30`→1, `:45`→2, `:00`→3. Every
seed still gets crawled once per hour overall, just as 4 smaller passes
instead of one large one — `crawl_due_seeds()` filters
`WHERE active = 1 AND seed_group = ?` using that slot's group.

**Why rotation, not "query everything every run":** measured locally, a
full pass over 30+ subjects × 3 APIs took 90+ seconds — comfortably over
the ~30s `max_execution_time` many shared-hosting cPanel plans enforce
regardless of `set_time_limit()` in the script. `run_api_harvest()`
processes a rotating batch of 3 subjects per run (cursor persisted in
`settings`), which brought a real run down to ~17-26s locally. Full
coverage of the seed list happens over several cron ticks instead of one —
an explicit trade of latency-to-full-coverage for per-run reliability,
appropriate for a background pipeline nobody is watching in real time.

### 4.4 Feedback: two independent channels, deliberately not merged

Two separate paths reach the same mailbox (`FEEDBACK_EMAIL`), and neither
should trigger the other:

1. **Floating widget** (`feedback_send.php`, no login) — sends a plain
   email via `send_email()`. Nothing more; it never touches the GitHub API.
2. **Email-to-issue automation** (`process_feedback_emails()` in
   `harvester.php`, rides along on the harvest cron, once/day internally
   via a `settings` date-gate) — polls that same mailbox over IMAP and
   turns every unread message into a GitHub issue.

Sending the widget's own output back into the mailbox the second mechanism
polls creates an obvious risk: without a way to tell them apart, every
widget submission would *also* become a GitHub issue, double-handling
something the widget's whole reason to exist was to keep out of GitHub
entirely. The fix is a subject-line marker (`[fdbk]`) the widget sets and
the poller explicitly checks for — mark as seen, skip, no issue — rather
than two channels that happen to coexist by accident.

### 4.5 Validator (cron-driven, full-catalog sweep)

Tag correction, zero-tag rescue, `General`-tag upgrade, junk-pruning,
language backfill, and link health (§4.2.2) all run inside `validator.php`
(`run_validator()`), a bounded cron batch on a 5-minute cadence — same
shape as harvest/discovery, no exception to the "page request or
short-lived cron invocation" pattern in §1.

**History:** an earlier version ran this as `validator_daemon.php`, a
single long-lived SSH-started process (`while (!$shuttingDown) { ...small
slice of each sub-task...; sleep(5); }`, hard `pcntl_alarm()` interrupts,
`bin/validator_watchdog.sh` cron restarting it if killed) on the theory
that a persistent process couldn't fall behind a fixed cron cadence under
load. In practice the host killed the daemon every 10-20 minutes
regardless — a host-level resource limit, confirmed via repeated `ps aux`
checks over SSH showing it gone with no crash logged — which made it
behave like a *badly* scheduled cron job anyway: worse than a real one,
since a genuine cron entry is at least reliably re-invoked on a fixed
cadence rather than restarted only whenever the watchdog next happened to
notice it was gone. `validator_daemon.php` and `bin/validator_watchdog.sh`
were retired; `validator.php` is now the only validator entrypoint.

**Tag maintenance was also redesigned at the same time.** It used to be
three separate functions (`retag_backlog_batch()`, reconciling tags
against a monotonic id cursor; `classify_zero_tag_backlog()` and
`reclassify_general_backlog()`, each sampling via `ORDER BY RAND() LIMIT
n`) independently re-fetching overlapping sets of items on three
schedules. RAND() sampling never guaranteed full backlog coverage — an
item stuck behind a genuine classification failure could get resampled
forever while other items were never drawn at all (confirmed on
production: `reclassify_general_backlog()` reported real, non-zero
`checked` counts sweep after sweep while the `General` count kept
climbing regardless). `sweep_backlog_batch()` replaces all three with one
function, one forward cursor (`sweep_cursor_v1`) over `items.id`, that
wraps back to 0 once it reaches the end rather than becoming a permanent
no-op — a genuine guarantee that every item gets reviewed every cycle, not
a statistical approximation of one.

**Language-scoped classification** (§4.3.1 note, `classify_subjects()`
in `includes/functions.php`): each item's `items.language` is now threaded
into every classification call site (API harvest, generic crawl, and all
three sub-tasks `sweep_backlog_batch()` folds together). Keywords in
`includes/subjects.php` are either bare (tested against every item
regardless of language) or language-prefixed (`fr:histoire`, tested only
when the item's detected language matches, or when the language is
unknown, in which case every keyword is tested as a safe fallback). This
replaced an earlier approach of adding foreign-language synonyms as bare,
unprefixed keywords — functionally fine (they still matched) but not
scoped to the language they were actually written for.

**Each sub-task runs in its own try/catch**, not one shared try/catch for
the whole run — confirmed in production that a failure early in the
sequence (a migration-ordering bug in `check_links_batch()`) silently
skipped every task after it, including totally unrelated ones, for as
long as that bug was live. One bad sub-task should never be able to
starve the others.

Run-locking (`acquire_run_lock('validator', VALIDATOR_MAX_RUNTIME_MINUTES)`)
and stale-run self-healing (`mark_stale_runs_as_crashed()`) are the same
pattern as the other cron-based jobs, so the display never gets stuck
reading "running…" after a genuine crash, and an occasional late/duplicate
cron fire can't double-process the same rows.

## 5. Indexing / search-performance plan

The browse/search UI (`index.php`) already does two things per request:
a MySQL `FULLTEXT` search (`MATCH ... AGAINST`) and a tag-scoped `JOIN`.
Once the harvester is running unattended, row counts grow continuously and
unpredictably, so indexing needs to be deliberate rather than default.

**Search match strategy**: rather than one fixed mode, a text query tries
three candidates in order — exact phrase, then all words required (each
with a trailing wildcard so a partial/prefix form still counts, e.g.
"chem" matches "chemistry"), then any word — each checked with a real
`COUNT` query, stopping at the first tier that finds something
(`search_match_candidates()` in `functions.php`). A flat OR-of-every-word
query (the original, simpler design) let a single ordinary word decide a
match regardless of relevance to the rest of the query — confirmed in
production, "periodic table elements" surfaced an HR article that only
shared the word "elements". The any-word tier is still the last resort,
not dropped: a genuine zero-result page is worse than today's closest
available match while the query gets queued for the harvester to try
finding something better — the results page just says so
(`$isLooseMatch`) rather than presenting a loose match as an exact hit.

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
seed_urls         id, url, subject_slug, seed_group, active, added_at,
                  last_crawled_at, successful_fetches, failed_fetches,
                  first_failed_at (self-migrated — see
                  ensure_seed_urls_first_failed_at_column(), §4.2.2),
                  block_cycles, permanently_disabled
crawl_queue       id, url, url_hash (unique), host, subject_slug,
                  status(pending/done/skipped/error), discovered_at,
                  processed_at, error
harvest_log       id, started_at, finished_at, run_type
                  (harvest/discovery/feedback/validator), items_added,
                  links_discovered, links_checked, links_validated
                  (self-migrated — see
                  ensure_harvest_log_links_validated_column()),
                  items_removed, errors, detail
subjects          id, slug (unique), label, parent, keywords —
                  self-migrated + self-seeded from includes/subjects.php
                  on first use (see §4.1); live source of truth from then on
items             + url_hash CHAR(64) UNIQUE, + idx_added_at,
                  + content_type ENUM(research/video), + language VARCHAR(8)
                  (self-migrated the same way as `subjects` — see
                  ensure_items_language_column()), + validation_group
                  TINYINT (0-5, self-migrated — see
                  ensure_items_validation_group_column(), §4.2.2/§4.5)
item_tags         + idx_tag_id
```

Full DDL lives in `sql/schema.sql`.

**Self-migrating schema changes**: no SSH access means no way to run a
migration script directly against production; `sql/schema.sql` is a
reference for a fresh install, not something re-run on every deploy. Both
`subjects` and `items.language` instead check `INFORMATION_SCHEMA`/a
`settings` flag on first use after deploy and `ALTER TABLE`/seed
themselves if missing (`ensure_subjects_table()`,
`ensure_items_language_column()`) — a `git push` alone is enough to roll
out a schema change, no manual DB step required.

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
  subject list is simple and will still mis-tag or under-tag edge cases —
  word-boundary matching and specific compound-phrase keywords (§4.1.1)
  closed the substring-false-positive class of bug, but a genuinely
  ambiguous phrase can still land wrong. The taxonomy is DB-backed and
  editable live from `subjects_admin.php` (§4.1), not a file needing a
  deploy, so tuning a pattern is now a same-minute fix rather than a
  code change.
- **Seed curation is manual**: the crawler only goes where seeds point it.
  This is intentional (bounded scope) but means crawl coverage is only as
  good as the seed list — worth revisiting periodically via `seeds.php`.
- **PubMed rate limits** without an API key are low (3 req/s); fine as-is —
  cron now fires every 15 min (§4.3), but each of the 6 content sources
  still self-throttles to at most once/hour independently of cron cadence
  (§4.1), so PubMed's actual call frequency hasn't changed. Would need
  `NCBI_API_KEY` set if that per-source cooldown itself were ever shortened.
- **cPanel cron availability**: confirmed as standard on the web host
  used in production (cPanel → Cron Jobs).
