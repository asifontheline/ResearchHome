# ResHub (Research Hub) ( https://www.reshub.in/ )

A self-updating catalog of freely available research — papers, patents,
articles, reports, anything with a URL — discovered automatically and
tagged by subject and source, no manual entry required. A scheduled
harvester pulls from six free sources (arXiv, Crossref, PubMed, OpenAlex,
Semantic Scholar, USPTO/PatentsView), plus a bounded, `robots.txt`-respecting
crawler seeded from hub/listing pages you configure. Every item is metadata
+ a link to the original source — never a copy of the content. This is
deliberately *not* an unrestricted web crawler (see `DESIGN.md` §2) — broad
coverage comes from stacking legitimate, free, structured sources rather
than an open-ended crawl of arbitrary sites.

Plain PHP + MySQL, no framework, no build step — built to run on ordinary
web-hosted cPanel shared hosting. See `DESIGN.md` for the full
architecture writeup.

## Features

### Cataloging
- **Multi-source harvesting** — 6 free structured APIs (arXiv, Crossref,
  PubMed, OpenAlex, Semantic Scholar, USPTO/PatentsView), cooldown-gated per
  source so frequent cron invocations no-op cheaply instead of re-hitting APIs
- **Bounded, polite crawler** — seeded from curated hub/listing pages,
  respects `robots.txt` (checked at both discovery and fetch time) and
  per-host crawl-delay, never an open-ended crawl of arbitrary sites
- **Source discovery** — proposes new crawler seeds by mining OpenAlex's own
  repository index and flagging listing-like pages the crawler encounters on
  domains it's never touched before; every proposal waits for admin review
- **Manual add/edit** — a fallback path, not the normal workflow; one-click
  metadata auto-fetch from a pasted URL (arXiv/PubMed/Crossref-aware, generic
  OpenGraph/meta-tag extraction otherwise), with an option to add the URL as
  a new crawler seed at the same time
- **Dedup by URL hash** on every insertion path (API harvest, crawler,
  manual add) — the same item never gets cataloged twice
- **Junk-title filtering** — rejects placeholder pages at insert time
  (Crossref's "Title Pending" pre-registered DOIs, 404/access-denied pages,
  bot-challenge pages, and pages whose title turns out to just be the
  site's own branding rather than an article — e.g. a defunct domain's
  category-listing pages posing as "articles"), centralized so every
  source is covered, not just one
- **Optional video harvesting** (YouTube/Vimeo) — kept as its own content
  type entirely separate from the research catalog (own section,
  `videos.php`, own subject rotation); silently inactive until an API
  key is configured

### Browsing & search
- **Subject browsing** — curated, DB-backed taxonomy (85 subjects across
  sciences, social sciences, humanities, arts, business, ...), grouped by
  parent category and editable live from the admin console (*Subjects*) —
  no deploy needed to add or tweak one
- **Tag browsing** — full directory of every tag, most-used first
- **No wrong-tag or zero-tag items** — word-boundary keyword matching (not
  a raw substring match) avoids false positives, and any item that
  genuinely can't be classified falls back to a `General` tag rather than
  landing with nothing — background passes keep retrying `General`-tagged
  and legacy-mistagged items as the taxonomy grows, so it isn't a
  permanent dumping ground
- **Language detection** — each item is tagged with its source language
  (`<html lang>` for crawled pages, each API's own reported language where
  available) and shown as a small badge, so a result in an unexpected
  language is obvious before you click
- **Combined full-text search** (MySQL `MATCH...AGAINST`), not a single
  fixed mode — tries an exact phrase, then all words (partial/prefix
  matching included), then any word, automatically, stopping at the first
  tier that finds something; only falls through to "nothing found" if
  every tier genuinely comes up empty
- **Search that queues its own gaps** — a zero-result search gets queued for
  the harvester to try directly next run, plus immediate fallback links to
  Google Scholar, Semantic Scholar, OpenAlex, arXiv, PubMed, CORE, and BASE
- **Sort by recency, relevance, or citation count** — citation counts
  captured from the sources that report one (OpenAlex, Semantic Scholar,
  Crossref)
- **Human-readable tags** — arXiv's raw category codes (`cs.LG`, `math.CO`,
  ...) expanded to plain-English labels across its full ~155-code taxonomy
- **In-page translation** — auto-suggested from the browser's own language
  header, drives Google's translation engine without ever showing its
  non-responsive dropdown UI
- **Activity page** — per-day, per-source "items added" chart (custom inline
  SVG, no charting library), recent harvest run history, the visitor map,
  and the active/disabled seed list (read-only for visitors; admins get an
  inline enable/disable toggle without leaving the page) — all public
- **Sticky header** — search box, subject filter, and nav stay reachable
  at the top of the viewport while scrolling, on every page

### Keeping the catalog honest
- **Reader-facing "Report broken link"** — no login, no GitHub issue; the
  script re-verifies the URL itself before removing anything
- **Continuous tag/URL validator** — a single long-running daemon
  (`validator_daemon.php`, started once via SSH, cron-watchdog-restarted if
  it ever dies) instead of a periodic batch job: small bounded slices of
  link-health checks, retagging, zero-tag rescue, `General`-upgrade, and
  language backfill every few seconds, so validation can't fall behind
  under high harvest volume the way a fixed cron cadence eventually would.
  Each slice runs under a hard `pcntl_alarm()` interrupt, not just a soft
  deadline check, so one hung network call can't wedge the whole process
- **Random-sample link health checks** — dead links get pruned automatically,
  but only after a consistent 3-strikes grace period for *every* failure
  code, 404/410 included, and a HEAD-request failure is always confirmed
  with a real GET before being trusted — some servers (WordPress/OpenEdition-
  hosted sites, confirmed in production) reject HEAD specifically while the
  page is genuinely live on GET. Sampling instead of a FIFO queue so
  there's no backlog that outgrows the catalog
- **No orphaned tags** — deleting an item, correcting a tag typo, or
  reconciling a mistag prunes any tag left with zero items as part of the
  same operation, not a deferred cleanup job
- **Credits that don't rot** — every publisher/repository on Credits links to
  its own site root (not a specific deep item page that can move or
  disappear), derived from real harvested URLs rather than guessed

### Reliability & ops
- **Self-healing run locks** — a crashed/killed run can't deadlock the next
  cron tick forever; the lock expires on its own before the next scheduled
  invocation would need it
- **Soft time-budget pattern** — checked between items, not mid-item; a run
  approaching its deadline finishes the item in progress and stops cleanly,
  picking up where it left off next run instead of losing work
- **MySQL reconnect-on-failure** — a dropped connection during a slow
  external API wait doesn't crash the whole run
- **Harvest capped to one run per 15-minute slot** regardless of cron
  misconfiguration or duplicate cron entries; seed crawling itself is split
  across 4 rotating groups tied to the slot, so every seed still gets
  crawled about once an hour without one run trying to cover all of them
- **Email monitoring digest** — self-expiring hourly status report (stuck
  runs, cron-not-firing detection, last N runs), rides along on the existing
  cron rather than needing its own
- **Watchdog cron for the validator daemon** — a lightweight `pgrep` check
  every ~10 minutes restarts `validator_daemon.php` if it's ever gone
  (host resource limit, OOM kill, reboot); does no validation work itself
- **Admin "run now"** buttons for on-demand harvest/discovery/validator
  runs outside the regular cron cadence
- **CI/CD via GitHub Actions** — push to `main` auto-deploys over FTPS,
  no build step, never deletes untracked server-side files; see
  "Continuous deployment" below for the full workflow

### Privacy, trust & legal
- **Privacy-respecting analytics** — page views and an approximate
  city/region/country per visitor, no raw IP ever stored (daily-rotating
  salted hash); visitors can opt out entirely, not just the site owner
- **World map of visitor locations** — pannable/zoomable, rendered
  server-side as inline SVG, no external map/JS library
- **Per-IP request rate limiting** — protects the server itself from a
  scraping burst, independent of the analytics-side bot filtering
- **Hardened session cookies** (`Secure`/`HttpOnly`/`SameSite=Strict`),
  sensitive files blocked via `.htaccess`, own `robots.txt` excluding
  login-gated admin routes from search engines
- **AGPL-3.0, genuinely open source** — copy it, self-host it, modify it;
  see `CONTRIBUTING.md` / `DESIGN.md`
- **"Nothing is copied"** — every item is metadata + a link back to the
  original source, never a mirror of the content itself

### Admin console
- Harvest/discovery/validator run history (last 3 days), seed management
  (add/approve/toggle/delete, incl. reviewing discovered-seed proposals),
  subject taxonomy management (add/edit/delete, no deploy needed), traffic
  dashboard with a pannable/zoomable visitor map filterable by day,
  zero-result search-miss queue, one-click "run harvest/discovery/
  validator now"

### Feedback
- **Floating feedback widget** — bottom-right on every page, a short message
  (plus an optional reply email) goes straight to the configured mailbox as
  plain email — deliberately does *not* create a GitHub issue
- **Email-to-issue automation** — a separate channel: mail sent directly to
  the feedback address gets polled once a day and turned into a GitHub
  issue automatically (explicitly skips anything sent via the widget above,
  so nothing gets double-handled)
- Pre-filled "bug report" / "feature request" GitHub issue links for readers
  who do have a GitHub account and want to file directly

## Local scheduling (until deployed)

Before this is deployed (where your host's cPanel Cron Jobs takes over —
see step 6 below), two macOS LaunchAgents run the harvester locally:

```
~/Library/LaunchAgents/com.researchhome.harvest.plist    every 15 min  → harvest.php
~/Library/LaunchAgents/com.researchhome.discover.plist   every 30 min  → discover.php
```

Logs: `logs/harvest.log`, `logs/discover.log`. Requires the Mac to be
logged in (LaunchAgents run per-user-session, not headless) and the local
MySQL service running (`brew services list` should show `mysql started`).

**Remove these once deployed** — otherwise local and production would both
be harvesting into different databases pointlessly, and content-source
cooldowns are per-database so it won't double-hit APIs, but there's no
reason to keep both running:
```
launchctl unload ~/Library/LaunchAgents/com.researchhome.harvest.plist
launchctl unload ~/Library/LaunchAgents/com.researchhome.discover.plist
rm ~/Library/LaunchAgents/com.researchhome.{harvest,discover}.plist
```

## What's here

```
config.php            DB credentials + app secret (edit this)
sql/schema.sql         Run this once in phpMyAdmin to create tables
setup.php              One-time web page to create your admin login (delete after use)
create_admin.php       CLI alternative to setup.php, if you have SSH/terminal
index.php / item.php   Browse / search / filter by tag, single item view
tags.php / credits.php  Full tag directory; publisher/source credits + blocked-seed links
videos.php             Optional video section (YouTube/Vimeo), separate from the research catalog
harvest.php            Content harvest entrypoint — run by cron, or on-demand from harvest_log.php
discover.php           Source-discovery entrypoint — separate cron/cadence from harvest.php
validator.php          One-batch tag/URL validation entrypoint — on-demand ("Run validator now") or cron fallback
validator_daemon.php   Continuous tag/URL validator — the normal way validation runs, see "Deploying" below
bin/validator_watchdog.sh  Cron script: restarts validator_daemon.php if it's ever not running
seeds.php              Admin: manage crawler seed/hub URLs (incl. discovered-seed review)
subjects_admin.php     Admin: add/edit/delete the subject taxonomy (DB-backed, no deploy needed)
subject_edit.php       Admin: edit one subject's label/parent/keywords
harvest_log.php        Admin: harvest/discovery/validator run history, traffic dashboard, "Run now" buttons
add.php / edit.php     Manual add/edit — a fallback path, not the normal workflow
delete.php             Delete an item (POST only, admin only)
report_broken_link.php  Reader-facing "report broken link", no login required
feedback_send.php      Backend for the floating feedback widget — email only, no GitHub issue
login.php / logout.php
includes/
  functions.php         DB/tag/search helpers, shared item-insert logic, link-health check
  harvester.php          API harvest (6 sources) + the bounded crawler
  subjects.php           One-time seed data for the subjects table on first run — not read after that
assets/                CSS + JS (fetch-metadata button, run-harvest button)
backups/               mysqldump snapshots (gitignored equivalent — .htaccess-blocked, not deployed)
```

## Continuous deployment

After the one-time manual setup in "Deploying" below, every subsequent
change ships itself — `.github/workflows/deploy.yml` defines a single
GitHub Actions workflow, **Deploy via FTP**:

- **Trigger**: any push to `main` (or a manual run via the Actions tab's
  "Run workflow" button — `workflow_dispatch`, useful if a deploy needs
  retriggering without a new commit, e.g. after rotating FTP credentials).
- **What it does**: checks out the repo (`actions/checkout@v4`), then
  uploads it over **FTPS** (encrypted, not plain FTP) to the host using
  [`SamKirkland/FTP-Deploy-Action`](https://github.com/SamKirkland/FTP-Deploy-Action),
  targeting `server-dir: /ResHome/`.
- **No build step** — there's nothing to compile; the files that get
  uploaded are exactly the files in the repo, verbatim.
- **What's excluded from every deploy**: `.git*`, `.github/`, `.claude/`,
  and — critically — `config.php`. The action's default "clean slate" mode
  (delete anything on the server not in this push) is explicitly **off**,
  so this is additive/overwrite-only: it never deletes server-side files
  the repo doesn't track. That's what keeps `config.php` (DB credentials,
  real per-environment secrets — not committed at all, see
  `config.example.php`), `backups/*.sql`, `logs/*.log`, and anything else
  written at runtime safe across every deploy.
- **Credentials**: `FTP_SERVER` / `FTP_USERNAME` / `FTP_PASSWORD` are
  GitHub Actions repo secrets (*Settings → Secrets and variables →
  Actions*), never committed. Set these once when forking/self-hosting.
- **Database migrations need no separate deploy step.** Schema changes
  ship as self-migrating code (`ensure_*_table()` / `ensure_*_column()`
  functions in `includes/functions.php` / `includes/harvester.php` that
  check `INFORMATION_SCHEMA` and `ALTER TABLE`/`CREATE TABLE IF NOT
  EXISTS` themselves on first use) rather than a migration script that has
  to be run manually — a plain shared-hosting constraint (no direct DB
  shell access assumed) that turned into a genuinely simpler deploy story:
  push to `main`, the next page load or cron tick that touches the
  changed table brings the schema up to date on its own.
- **The one thing this workflow can't do**: restart `validator_daemon.php`
  (see "Deploying" step 6b) — a running PHP process doesn't pick up
  changed code on disk. After a deploy that touches `validator_daemon.php`
  itself, `kill` the running PID over SSH; the watchdog cron (or a manual
  `nohup ... &`) brings it back up on the new code within ~10 minutes.
  Everything else (page requests, cron-invoked `harvest.php`/`discover.php`/
  `validator.php`) always runs the latest deployed code automatically,
  since PHP re-reads the file from disk on every request/invocation.

## Deploying to web-hosted cPanel shared hosting

1. **Create the MySQL database.**
   cPanel → *MySQL Databases* → create a database (e.g. `researchhome`) and a
   user, add the user to the database with **All Privileges**. Note the full
   names cPanel gives them — they'll be prefixed with your cPanel username,
   e.g. `youracct_researchhome` / `youracct_rhuser`.

2. **Import the schema.**
   cPanel → *phpMyAdmin* → select the new database → *Import* →
   choose `sql/schema.sql` → Go.

3. **Upload the files.**
   cPanel → *File Manager* (or FTP) → upload everything in this project into
   the document root for the domain/subdomain you want it on
   (e.g. `public_html/research` or a dedicated subdomain's folder).

4. **Edit `config.php`** in place (File Manager → Edit) with the DB name,
   user, password from step 1, and set `APP_SECRET` to a long random string.

5. **Create your admin login.**
   Visit `https://yourdomain/setup.php` in a browser, fill in a username and
   password (8+ characters). **Then delete `setup.php` from the server** —
   it refuses to run again once a user exists, but there's no reason to leave
   it up.

6. **Set up two cron jobs — content harvest and source discovery are separate.**
   Most web hosts expose a cPanel-based control panel with a *Cron Jobs*
   section (find yours via the hosting package's login page → *Cron Jobs* →
   *Add Cron Job*). The command needs the PHP binary's **absolute path**
   (not just `php`) and should redirect output to a log file — cron runs in
   a stripped-down environment where relative paths and `$PATH` assumptions
   can silently fail:
   ```
   Command:  /usr/bin/php /home/YOURUSER/public_html/PATH/harvest.php >> /home/YOURUSER/public_html/PATH/logs/harvest.log 2>&1
   Schedule: */15 * * * *       (every 15 minutes)

   Command:  /usr/bin/php /home/YOURUSER/public_html/PATH/discover.php >> /home/YOURUSER/public_html/PATH/logs/discover.log 2>&1
   Schedule: */30 * * * *       (every 30 minutes)
   ```
   `/usr/bin/php` is a common example — if your account has a specific PHP
   version selected, confirm the actual path (SSH: `which php`, or ask your
   host's support) before relying on it; a wrong path fails silently under
   cron even though the same command works fine typed manually.
   `logs/` already exists in this repo with an `.htaccess` blocking web
   access, so logging cron output there is safe.

   `harvest.php` rotates through **one** subject across all 6 sources (not
   the whole 30+-subject list — see `DESIGN.md` §4.3/§5 for why), crawls its
   assigned quarter of the active seed list (see below), and processes a
   batch of the discovered-link queue. Full subject coverage happens across
   many runs, by design. Each of the 6 content sources self-throttles to at
   most once per hour regardless of cron cadence, so running this every 15
   minutes doesn't over-fetch them — it just means the seed-crawling and
   queue-processing parts of the run get 4x more wall-clock time per hour
   to work through, which matters more as the seed list and catalog grow.
   `harvest_already_ran_this_slot()` still caps it to one run per 15-minute
   window even if the cron entry misfires more often than that. Link-health
   checking, retagging, and language backfill are **not** part of
   `harvest.php` at all — see step 6b below for the continuous validator
   that owns all of that.

   **Seed crawling is split into 4 rotating groups**, not "all active seeds
   every run" — each seed gets a `seed_group` (0-3), assigned round-robin
   (a persistent cursor in `settings`, not `id % 4` — that drifts uneven
   once seeds get deleted) the moment it's added or approved. Which group
   runs is purely a function of the current 15-minute slot
   (`current_seed_group()` in `includes/harvester.php`): `:15`→group 0,
   `:30`→group 1, `:45`→group 2, `:00`→group 3. Every seed still gets
   crawled once per hour overall, just spread across 4 smaller runs instead
   of one large one — this matters once the seed list is large enough that
   crawling all of it took several minutes on its own.

   `discover.php` only proposes new seeds (see step 7) — no content
   fetching — and self-throttles to once per 24h internally, so running it
   every 30 minutes just means a new source gets picked up promptly once a
   day rather than fetching anything more often than that.

6b. **Start the continuous validator (requires SSH; no direct cPanel
    equivalent).** Tag correction, zero-tag rescue, link-health checks, and
    language backfill run as their own long-lived process rather than a
    cron batch, so they can't fall behind under high harvest volume. Over
    SSH, from the app directory:
    ```
    nohup php validator_daemon.php >> logs/validator_daemon.log 2>&1 &
    ```
    Then add a watchdog cron so it restarts itself if the host ever kills
    it (OOM, wall-clock/resource limit, reboot) — this one *does* go in
    cPanel's Cron Jobs, since it's just a cheap periodic check, not the
    long-lived process itself:
    ```
    Command:  /bin/sh /home/YOURUSER/public_html/PATH/bin/validator_watchdog.sh
    Schedule: */10 * * * *       (every 10 minutes)
    ```
    Calling it via `/bin/sh <path>` rather than executing the script path
    directly avoids depending on the execute bit surviving an FTP deploy.
    Skipping this step entirely still works — `validator.php` (a single
    bounded batch, same logic, no daemon) runs from the admin "Run
    validator now" button and could be cron'd the conventional way instead
    if you'd rather not run a persistent process at all — it just won't
    keep up as well once harvest volume is high.

7. **Add a few seed URLs — or let the harvester find them.**
   Log in → *Seeds* → add hub/listing pages yourself (e.g. an arXiv category
   listing, a topic RSS feed), or just leave it — every harvest run also
   proposes new candidate seeds automatically: mining OpenAlex's curated
   index of real repositories/journals, and flagging pages the crawler
   encounters that look like listing pages on a domain it's never seen
   before. Proposals land in *Seeds → Pending review*, inactive until you
   approve them — nothing gets crawled without a human saying yes.

8. **Watch it work.**
   *Harvest log* shows every run (items added, links discovered, errors), or
   click "Run harvest now" for an on-demand run instead of waiting for cron.

`config.php`, `create_admin.php`, and the `includes/` and `sql/` folders are
blocked from direct web access via `.htaccess`, but double-check your host
serves `.htaccess` (Apache/LiteSpeed are the common defaults — both do).

## Notes

- **Tags are fully freeform**, and not capped at a fixed list. The curated
  subject taxonomy (85 entries, DB-backed — edit live from *Subjects* in the
  admin console, `includes/subjects.php` is one-time seed data only, not
  read again after the first run) drives API search queries and keyword
  classification, but actual tagging goes further: arXiv items also get
  arXiv's own declared category codes (`cs.LG`, `q-bio.NC`, `astro-ph.CO`,
  ...) straight from the API response, and Crossref/OpenAlex items get
  their own subject/topic strings when present. New tags are created on the
  fly, same as manual tagging always worked. Anything that can't be
  classified at all gets a `General` fallback rather than landing with zero
  tags, and background passes keep retrying it against the current
  taxonomy rather than leaving it there permanently.
- **Classification is keyword/heuristic-based**, not perfect — a paper can
  pick up a tag from an incidental keyword match. Word-boundary matching
  (not a raw substring search) and specific compound-phrase keywords
  (avoiding generic single words that show up constantly in unrelated
  writing) cut down false positives; tune the taxonomy from *Subjects* in
  the admin console if you notice a pattern.
- **The crawler is intentionally bounded**: single hop from configured seed
  pages, `robots.txt`-checked and rate-limited per host, no recursive
  crawling. It is not, and isn't meant to be, an unrestricted web spider —
  see `DESIGN.md` §2 for why.
- The "Fetch metadata" button on the manual Add page recognizes arXiv,
  PubMed, and DOI (Crossref) links, falling back to Open Graph/meta tags for
  anything else. Manual add/edit still exists as a fallback, not the normal
  workflow.
- Search uses MySQL full-text search over title, authors, abstract, and
  notes, cascading through exact-phrase → all-words → any-word automatically
  rather than one fixed mode; browsing is tag-filtered and can be scoped to
  a subject directly from the header search box. See `DESIGN.md` §5 for the
  indexing strategy and the scale threshold at which it's worth revisiting.
- Single shared admin login by design (personal catalog, not multi-user).
  Run `setup.php` again after clearing the `users` table via phpMyAdmin if
  you ever need to reset the password.
- **Broken links are removed automatically.** The validator daemon checks a
  batch of existing items' URLs on every iteration; every failure code,
  404/410 included, gets up to 3 tries across separate checks before
  removal, and a HEAD-request failure is always confirmed with a real GET
  before it's trusted (some servers reject HEAD specifically while the
  page is genuinely live).
- Three optional API keys in `config.php` — all work fine unset, all free to
  register:
  - `NCBI_API_KEY` — raises PubMed's low unauthenticated limit (3 req/s).
  - `SEMANTIC_SCHOLAR_API_KEY` — Semantic Scholar's unauthenticated pool is
    shared globally across every caller and 429s often; a free key helps a lot.
  - `PATENTSVIEW_API_KEY` — required to harvest patents at all (PatentsView
    has no unauthenticated tier); free self-serve signup at
    patentsview.org/apis/keyrequest. Patent harvesting silently no-ops until set.
- **Set `CONTACT_EMAIL` in `config.php`** before deploying. Crossref's
  "polite pool", NCBI's E-utilities usage policy, and the crawler's
  User-Agent string all identify this app using it — several sources give
  identified callers more reliable service than anonymous ones.
- **Per-source cooldown**: each of the 6 harvest sources is called at most
  once per hour, tracked independently of cron cadence — clicking "Run
  harvest now" repeatedly won't hammer any source; it'll just report those
  sources as skipped until their hour is up.
- **Tag display is curated, not unbounded.** Source-specific codes (arXiv
  categories, Crossref subject strings, ...) that only accumulate 1-2 items
  don't get their own row in the browse UI — the item is still reachable via
  whatever real subject tag it also carries. Ones that accumulate more than
  2 items graduate into a visible "Specialized Topics" group automatically.
- **Local testing**: `backups/` holds `mysqldump` snapshots taken between
  test cycles so accumulated harvest data survives schema changes — schema
  updates should prefer `ALTER TABLE`/`CREATE TABLE IF NOT EXISTS` over
  dropping the database where at all possible.

## License

AGPL-3.0 — see [`LICENSE`](LICENSE). Copy, modify, and self-host this freely;
if you run a modified version as a public service, you're required to make
your modified source available to its users (that's what AGPL adds over
plain GPL — it closes the "network use" loophole). Contributions back
upstream are very welcome — see [`CONTRIBUTING.md`](CONTRIBUTING.md).
