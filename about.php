<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'About';
require __DIR__ . '/includes/header.php';
?>

<h1>About ResHub</h1>

<p class="project-description" style="max-width: 70ch;">
  ResHub (Research Hub) is a self-updating catalog of freely available research —
  papers, patents, and articles — discovered automatically and tagged by subject
  and source, with no manual entry required. It's a dynamic site: new items are
  added on an hourly harvest, with a discovery phase every half hour looking for new sources.
</p>

<h2>How it works</h2>
<p class="muted" style="max-width: 70ch;">
  A scheduled harvester pulls from six free, structured sources — arXiv, Crossref,
  PubMed, OpenAlex, Semantic Scholar, and USPTO/PatentsView — plus a bounded,
  <code>robots.txt</code>-respecting crawler seeded from hub/listing pages. A
  separate source-discovery job proposes new seeds by mining OpenAlex's own
  curated index of repositories and journals, and by flagging pages the crawler
  encounters that look like listings on a domain it's never touched before.
  Nothing is copied — every item is metadata plus a link back to its original
  source. See <a href="/credits.php">Credits</a> for the full source list and
  <a href="/activity.php">Activity</a> for live harvest history.
</p>

<h2>Feedback</h2>
<p class="muted" style="max-width: 70ch;">
  Found a bug, or have an idea? Opening a
  <a href="https://github.com/asifontheline/ResearchHome/issues/new?labels=bug&title=%5BBug%5D%20" target="_blank" rel="noopener noreferrer">bug report</a>
  or
  <a href="https://github.com/asifontheline/ResearchHome/issues/new?labels=enhancement&title=%5BFeature%20request%5D%20" target="_blank" rel="noopener noreferrer">feature request</a>
  on GitHub is the most direct way to reach the person running this — no
  account needed to read <a href="https://github.com/asifontheline/ResearchHome/issues" target="_blank" rel="noopener noreferrer">existing issues</a>,
  just to open a new one. No GitHub account?
  <?php
    $feedbackEmail = (defined('FEEDBACK_EMAIL') && FEEDBACK_EMAIL) ? FEEDBACK_EMAIL
        : ((defined('CONTACT_EMAIL') && CONTACT_EMAIL !== 'you@example.com') ? CONTACT_EMAIL : null);
  ?>
  <?php if ($feedbackEmail): ?>
    <a href="mailto:<?= h($feedbackEmail) ?>">Email instead</a> — it'll get turned into an issue.
  <?php else: ?>
    Email works too — see the site's contact address.
  <?php endif; ?>
</p>

<h2>Open source</h2>
<p class="muted" style="max-width: 70ch;">
  ResHub is free and open source under
  <a href="https://github.com/asifontheline/ResearchHome/blob/main/LICENSE" target="_blank" rel="noopener noreferrer">AGPL-3.0</a> —
  copy it, self-host it, modify it freely. If you run a modified version as a
  public service, the license asks that you make your modified source available
  to its users too.
</p>
<p class="muted" style="max-width: 70ch;">
  Source code, issues, and pull requests:
  <a href="https://github.com/asifontheline/ResearchHome" target="_blank" rel="noopener noreferrer">github.com/asifontheline/ResearchHome</a>.
  Contributions are genuinely welcome — new subjects, new free API sources, better
  classification heuristics, bug fixes. See
  <a href="https://github.com/asifontheline/ResearchHome/blob/main/CONTRIBUTING.md" target="_blank" rel="noopener noreferrer">CONTRIBUTING.md</a>
  for what to know before opening a PR, and
  <a href="https://github.com/asifontheline/ResearchHome/blob/main/DESIGN.md" target="_blank" rel="noopener noreferrer">DESIGN.md</a>
  for the full architecture writeup.
</p>

<?php require __DIR__ . '/includes/footer.php'; ?>
