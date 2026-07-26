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

// Optional: NCBI E-utilities API key increases PubMed rate limits (not required).
define('NCBI_API_KEY', '');

// Optional: enables USPTO patent harvesting via PatentsView. Free self-serve
// key at https://patentsview.org/apis/keyrequest — leave blank to skip patents.
define('PATENTSVIEW_API_KEY', '');

// Optional: raises Semantic Scholar's rate limit (their unauthenticated pool
// is shared globally and 429s often). Free self-serve key at
// https://www.semanticscholar.org/product/api#api-key-form — works without it too.
define('SEMANTIC_SCHOLAR_API_KEY', '');
