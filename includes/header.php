<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

// Public-visitor traffic only — not the admin's own logged-in usage.
if (!current_user()) {
    record_page_view();
}

// Auto-suggest a translation language from the browser's own Accept-Language
// header — a more reliable "region" signal than guessing from IP-derived
// country (a country can have many languages; Accept-Language is literally
// what the browser sends for exactly this purpose). Only sets this once:
// if the googtrans cookie already exists, a choice was already made
// (Google's own widget writes this cookie itself when used) — never
// override that on a later page load.
if (!isset($_COOKIE['googtrans'])) {
    $acceptLang = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
    $firstTag = strtolower(trim(explode(';', explode(',', $acceptLang)[0])[0]));
    $preferredLang = explode('-', $firstTag)[0];
    if (preg_match('/^[a-z]{2}$/', $preferredLang) && $preferredLang !== 'en') {
        setcookie('googtrans', '/en/' . $preferredLang, [
            'expires' => time() + 30 * 24 * 3600, 'path' => '/', 'secure' => is_https(),
            'httponly' => false, 'samesite' => 'Lax', // false: our own JS below reads/writes this too
        ]);
        $_COOKIE['googtrans'] = '/en/' . $preferredLang; // so the select below reflects it on this same load
    }
}
// Pre-select our dropdown to match whatever's already active (auto-detected
// above, or a prior choice), so it doesn't always show "Original" for a
// returning visitor who's already translated.
$currentLangCode = 'en';
if (!empty($_COOKIE['googtrans']) && preg_match('#^/en/([a-zA-Z-]+)$#', $_COOKIE['googtrans'], $m)) {
    $currentLangCode = $m[1];
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= isset($pageTitle) ? h($pageTitle) . ' · ' : '' ?>ResHub</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>%F0%9F%94%AD</text></svg>">
<link rel="stylesheet" href="/assets/style.css?v=<?= (int) filemtime(__DIR__ . '/../assets/style.css') ?>">
</head>
<body>
<header class="site-header">
  <a class="brand" href="/index.php">🔭 ResHub</a>
  <?php
    // Subject select lives in the header so a search can be scoped to a
    // subject from any page, not just after already clicking into one on
    // the homepage — combines with the text box (both are just GET params
    // on the same form, ANDed together same as clicking a subject pill
    // then typing does). Grouped subjects only, not the full specialized-
    // tag list (tags.php) — that list is long enough it'd overwhelm this
    // dropdown; deep-linking to a specific tag still works via its own URL.
    $headerGrouped = get_grouped_subjects();
    $currentTagSlug = trim($_GET['tag'] ?? '');
  ?>
  <form class="search-form" action="/index.php" method="get">
    <input type="text" name="q" placeholder="Search title, authors, abstract, notes…" value="<?= h($_GET['q'] ?? '') ?>">
    <select name="tag" aria-label="Limit search to a subject" onchange="this.form.submit()">
      <option value="">All subjects</option>
      <?php foreach ($headerGrouped['groups'] as $parent => $groupSubjects): ?>
        <optgroup label="<?= h($parent) ?>">
          <?php foreach ($groupSubjects as $s): ?>
            <option value="<?= h($s['slug']) ?>" <?= $currentTagSlug === $s['slug'] ? 'selected' : '' ?>><?= h($s['label']) ?> (<?= (int)$s['count'] ?>)</option>
          <?php endforeach; ?>
        </optgroup>
      <?php endforeach; ?>
    </select>
    <button type="submit">Search</button>
  </form>
  <div class="translate-toggle" translate="no">
    <select id="reshub-lang-select" aria-label="Translate this page">
      <option value="en">Original (English)</option>
      <option value="es">Español</option>
      <option value="fr">Français</option>
      <option value="de">Deutsch</option>
      <option value="pt">Português</option>
      <option value="it">Italiano</option>
      <option value="ru">Русский</option>
      <option value="zh-CN">中文 (简体)</option>
      <option value="ja">日本語</option>
      <option value="ko">한국어</option>
      <option value="ar">العربية</option>
      <option value="hi">हिन्दी</option>
      <option value="bn">বাংলা</option>
      <option value="ur">اردو</option>
      <option value="id">Bahasa Indonesia</option>
      <option value="vi">Tiếng Việt</option>
      <option value="tr">Türkçe</option>
      <option value="nl">Nederlands</option>
      <option value="pl">Polski</option>
      <option value="th">ไทย</option>
      <option value="sw">Kiswahili</option>
    </select>
    <script>document.getElementById('reshub-lang-select').value = <?= json_encode($currentLangCode) ?>;</script>
  </div>
  <div id="google_translate_element" style="display:none;"></div>
  <div class="header-right">
    <?php $summary = get_catalog_summary(); ?>
    <table class="summary-box">
      <tr><td>Items</td><td><?= number_format($summary['total_items']) ?></td></tr>
      <tr><td>Tags</td><td><?= number_format($summary['total_tags']) ?></td></tr>
      <tr><td>Sources</td><td><?= number_format($summary['total_sources']) ?></td></tr>
      <?php if ($summary['last_run']): ?>
        <tr><td>Last harvest (UTC)</td><td><?= h(substr($summary['last_run']['started_at'], 5, 11)) ?></td></tr>
      <?php endif; ?>
    </table>
    <?php
      // Every 1,000 items gets a little celebration next to the stat box.
      // Recomputed on every load, so it always reflects the most recently
      // crossed thousand, not a one-time event that needs tracking
      // anywhere. Shown on every page (not just the homepage, as before)
      // -- gating it to $isHomePage made the header a different width on
      // index.php than everywhere else, so the shared header wasn't
      // actually identical across pages.
      $milestoneK = intdiv($summary['total_items'], 1000);
    ?>
    <?php if ($milestoneK >= 1): ?>
      <div class="milestone-badge" aria-label="<?= $milestoneK ?>,000+ milestone">
        <span class="milestone-blast" aria-hidden="true"></span>
        <span class="milestone-text">&#127881; <span id="milestone-num" data-target="<?= $milestoneK * 1000 ?>">0</span>+</span>
      </div>
      <script>
      (function () {
        var el = document.getElementById('milestone-num');
        if (!el) return;
        var target = parseInt(el.getAttribute('data-target'), 10) || 0;
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
          el.textContent = target.toLocaleString();
          return;
        }
        var duration = 1200, start = null;
        function step(ts) {
          if (start === null) start = ts;
          var progress = Math.min((ts - start) / duration, 1);
          el.textContent = Math.floor(progress * target).toLocaleString();
          if (progress < 1) requestAnimationFrame(step);
        }
        requestAnimationFrame(step);
      })();
      </script>
    <?php endif; ?>
    <nav>
      <a href="/index.php">Home</a>
      <a href="/activity.php">Activity</a>
      <a href="/tags.php">Tags</a>
      <a href="/credits.php">Credits</a>
      <a href="/about.php">About</a>
      <?php if (current_user()): ?>
        <a href="/harvest_log.php">Harvest log</a>
        <a href="/seeds.php">Seeds</a>
        <a href="/subjects_admin.php">Subjects</a>
        <a href="/add.php">+ Add</a>
        <a href="/logout.php">Log out</a>
      <?php endif; ?>
      <?php /* No public "Log in" link — this is a single-admin site, not a
               multi-user app; showing it to visitors implied accounts exist.
               /login.php still works directly for the admin. */ ?>
    </nav>
  </div>
</header>
<main class="container">
