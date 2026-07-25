# Contributing

ResearchHome is AGPL-3.0 — you're free to fork and run your own instance.
If you improve something, contributions back upstream are genuinely welcome,
not just legally invited.

## Useful contributions

- New subjects/keywords in `includes/subjects.php`
- New free API sources in `includes/harvester.php` (follow the existing
  `api_harvest_*()` pattern — dedupe by `url_hash`, tag with the source's own
  classification where available, respect rate limits)
- Better classification heuristics in `classify_subjects()`
- Crawler seed suggestions via the app's own `seeds.php` discovery UI
- Bug fixes, obviously

## Before opening a PR

- `php -l` every changed file — no build step, so this is the whole CI
- If you touch `sql/schema.sql`, add the matching `ALTER TABLE` for people
  with existing data — `CREATE TABLE IF NOT EXISTS` alone won't migrate them
- Keep it framework-free (plain PHP + MySQL, no Composer dependencies) —
  that's a deliberate constraint for shared-hosting compatibility, not an
  oversight
- See `DESIGN.md` for the architecture and the reasoning behind the bounded
  crawler, per-source cooldowns, and indexing choices before proposing
  something that works against them
