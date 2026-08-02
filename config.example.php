<?php
// Fill these in with the MySQL database you create in cPanel > MySQL Databases.
// cPanel usually prefixes both the DB name and DB user with your account username,
// e.g. cpaneluser_researchhome / cpaneluser_rhuser
define('DB_HOST', 'localhost');
define('DB_NAME', 'researchhome');
define('DB_USER', 'root');
define('DB_PASS', '');

// Optional: if your host provides a MySQL Unix socket path (check your
// panel's database connection info — often /run/mysqld/mysqld.sock or
// /var/lib/mysql/mysql.sock) and the app runs on the same server as the
// database, this is faster/more reliable than DB_HOST/TCP. Leave blank to
// use DB_HOST instead.
define('DB_SOCKET', '');

// Used for admin login sessions. Change this to a random string.
define('APP_SECRET', 'change-this-to-a-long-random-string');

// Used to identify this app to the APIs/sites it queries (Crossref's "polite
// pool", NCBI's E-utilities usage policy, and the crawler's User-Agent all
// expect a real contact). Set this to a real address before deploying —
// several sources treat identified callers noticeably better than anonymous ones.
define('CONTACT_EMAIL', 'you@example.com');

// Shown on About as the no-GitHub-account fallback for bug reports/feature
// requests — separate from CONTACT_EMAIL above (that one's for API
// politeness/identification, a different audience). Falls back to
// CONTACT_EMAIL if left blank.
define('FEEDBACK_EMAIL', '');

// Optional: NCBI E-utilities API key increases PubMed rate limits (not required).
define('NCBI_API_KEY', '');

// Optional: enables USPTO patent harvesting via PatentsView. Free self-serve
// key at https://patentsview.org/apis/keyrequest — leave blank to skip patents.
define('PATENTSVIEW_API_KEY', '');

// Optional: raises Semantic Scholar's rate limit (their unauthenticated pool
// is shared globally and 429s often). Free self-serve key at
// https://www.semanticscholar.org/product/api#api-key-form — works without it too.
define('SEMANTIC_SCHOLAR_API_KEY', '');

// Optional: if set, monitor.php (run hourly via its own mPanel cron job)
// emails a status digest here every run — catalog totals, recent harvest/
// discovery runs, and any stuck or errored runs. Leave blank to disable.
define('MONITOR_EMAIL', '');

// Optional: enables video harvesting (videos.php, a separate section from
// the research catalog — see includes/harvester.php's api_harvest_youtube).
// Free key from Google Cloud Console (enable "YouTube Data API v3").
// Quota: 10,000 units/day, a search costs 100 — fine for one search/run.
define('YOUTUBE_API_KEY', '');

// Optional: enables Vimeo video harvesting. Free personal access token from
// https://developer.vimeo.com/apps (create an app, generate an
// unauthenticated/personal token with "Public" scope).
define('VIMEO_ACCESS_TOKEN', '');

// Optional: auto-creates a GitHub issue (labeled 'email-submission', not
// pre-vetted — see process_feedback_emails() in includes/harvester.php) for
// every unread email in FEEDBACK_EMAIL's inbox, then marks it read. Rides
// along on the harvest cron, no separate cron entry needed. Needs a
// fine-grained GitHub personal access token scoped to ONLY this repo with
// ONLY "Issues: Read and write" permission — nothing broader. Leave any of
// these blank to disable entirely (falls back to "email reaches you, you
// decide whether to open an issue yourself").
define('FEEDBACK_IMAP_HOST', '');       // e.g. mail.yourdomain.com
define('FEEDBACK_IMAP_PORT', '993');    // IMAP-over-SSL, standard port
define('FEEDBACK_IMAP_USER', '');       // usually same as FEEDBACK_EMAIL
define('FEEDBACK_IMAP_PASSWORD', '');
define('GITHUB_TOKEN', '');
define('GITHUB_REPO', 'asifontheline/ResearchHome');

// Optional: lets a real cron job (curl/wget) drive tag_cleanup_worker.php
// unattended, via ?key=<this value>, instead of needing an admin browser
// tab left open. Generate your own with:
//   php -r "echo bin2hex(random_bytes(24));"
// Leave blank to require the normal admin login instead (default).
define('TAG_CLEANUP_KEY', '');
