# ResearchHome

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
cPanel shared hosting (tested against a MilesWeb Premium plan). See
`DESIGN.md` for the full architecture writeup.

## Local scheduling (until deployed)

Before this is deployed to MilesWeb (where cPanel Cron Jobs takes over —
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
harvest.php            Harvester entrypoint — run by cron, or on-demand from harvest_log.php
seeds.php              Admin: manage crawler seed/hub URLs
harvest_log.php        Admin: harvest run history + "Run harvest now"
add.php / edit.php     Manual add/edit — a fallback path, not the normal workflow
delete.php             Delete an item (POST only, admin only)
login.php / logout.php
includes/
  functions.php         DB/tag/search helpers, shared item-insert logic, link-health check
  harvester.php          API harvest (6 sources) + the bounded crawler
  subjects.php           Seed subject/keyword list, grouped by parent category — edit freely
assets/                CSS + JS (fetch-metadata button, run-harvest button)
backups/               mysqldump snapshots (gitignored equivalent — .htaccess-blocked, not deployed)
```

## Deploying to MilesWeb (cPanel shared hosting)

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

6. **Set up the cron job.**
   cPanel → *Cron Jobs* → add a new job:
   ```
   Command:  php /home/YOURUSER/public_html/PATH/harvest.php
   Schedule: every 1 hour (or whatever cadence you like)
   ```
   Each run rotates through **one** subject across all 6 sources (not the
   whole 30+-subject list — see `DESIGN.md` §4.3/§5 for why), crawls one due
   seed hub page, processes a batch of the discovered-link queue, and
   verifies a batch of existing items are still reachable (removing dead
   links). Full subject coverage happens across many runs, by design — this
   keeps each cron invocation (measured locally at ~30s worst case with all
   6 sources) well under typical shared-hosting PHP execution-time limits.

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
serves `.htaccess` (Apache/LiteSpeed — the MilesWeb default — both do).

## Notes

- **Tags are fully freeform**, and not capped at a fixed list. `subjects.php`
  is a *seed* list that drives API search queries and keyword classification
  — it's deliberately broad (30+ subjects across sciences, social sciences,
  humanities) and easy to extend, but actual tagging goes further: arXiv
  items also get arXiv's own declared category codes (`cs.LG`, `q-bio.NC`,
  `astro-ph.CO`, ...) straight from the API response, and Crossref items get
  Crossref's own subject-area strings when present. New tags are created
  on the fly, same as manual tagging always worked.
- **Classification is keyword/heuristic-based**, not perfect — a paper can
  pick up a tag from an incidental keyword match. Acceptable trade-off for
  an unattended pipeline; tune `subjects.php` if you notice a pattern.
- **The crawler is intentionally bounded**: single hop from configured seed
  pages, `robots.txt`-checked and rate-limited per host, no recursive
  crawling. It is not, and isn't meant to be, an unrestricted web spider —
  see `DESIGN.md` §2 for why.
- The "Fetch metadata" button on the manual Add page recognizes arXiv,
  PubMed, and DOI (Crossref) links, falling back to Open Graph/meta tags for
  anything else. Manual add/edit still exists as a fallback, not the normal
  workflow.
- Search uses MySQL full-text search over title, authors, abstract, and
  notes; browsing is tag-filtered. See `DESIGN.md` §5 for the indexing
  strategy and the scale threshold at which it's worth revisiting.
- Single shared admin login by design (personal catalog, not multi-user).
  Run `setup.php` again after clearing the `users` table via phpMyAdmin if
  you ever need to reset the password.
- **Broken links are removed automatically.** Every harvest run checks a
  batch of existing items' URLs; a 404/410 removes the item immediately,
  other failures (timeouts, 5xx) get up to 3 tries across separate runs
  first, in case it's transient.
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
  2 items graduate into a visible "Emerging Topics" group automatically.
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
