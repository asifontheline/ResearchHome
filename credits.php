<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Credits';
require __DIR__ . '/includes/header.php';
?>

<h1>Credits</h1>

<div class="callout">
  <strong>Nothing is copied here.</strong> ResHub (Research Hub) stores only metadata —
  title, authors, a short abstract, publication date — plus a link back to
  where each item actually lives. No full text, no paywalled content, no
  bypassing of access controls. This is a personal, non-commercial research
  catalog, not a mirror or a republisher. Every source below explicitly
  provides its metadata through a public API meant for exactly this kind of
  downstream use.
</div>

<p class="muted">
  Sources are listed below in the order the harvester queries them each run
  (see <code>includes/harvester.php</code>).
</p>

<ol class="credits-list">
  <li>
    <strong><a href="https://arxiv.org" target="_blank" rel="noopener noreferrer">arXiv</a></strong>
    <span class="muted">— Cornell University. Open-access preprints in physics, CS, math, and quantitative biology.</span>
  </li>
  <li>
    <strong><a href="https://www.crossref.org" target="_blank" rel="noopener noreferrer">Crossref</a></strong>
    <span class="muted">— DOI registration agency. Journal-article metadata across essentially every academic publisher.</span>
  </li>
  <li>
    <strong><a href="https://pubmed.ncbi.nlm.nih.gov" target="_blank" rel="noopener noreferrer">PubMed</a></strong>
    <span class="muted">— National Library of Medicine / NCBI. Medicine and life sciences literature.</span>
  </li>
  <li>
    <strong><a href="https://openalex.org" target="_blank" rel="noopener noreferrer">OpenAlex</a></strong>
    <span class="muted">— OurResearch. ~250M scholarly works across every field; also used to discover new research-source seeds (see below).</span>
  </li>
  <li>
    <strong><a href="https://www.semanticscholar.org" target="_blank" rel="noopener noreferrer">Semantic Scholar</a></strong>
    <span class="muted">— Allen Institute for AI. Strong computer science, AI, and biomedical coverage, plus citation data.</span>
  </li>
  <li>
    <strong><a href="https://patentsview.org" target="_blank" rel="noopener noreferrer">PatentsView</a></strong>
    <span class="muted">— USPTO. US patent data (only active once a free API key is configured).</span>
  </li>
</ol>

<h2>Source discovery</h2>
<p class="muted">
  New crawler seeds are proposed (not auto-added — every one waits for admin
  review) two ways: mining <a href="https://openalex.org/sources" target="_blank" rel="noopener noreferrer">OpenAlex's own curated index</a>
  of journals and repositories, and flagging pages the crawler encounters that
  look like listing/index pages on a domain it's never touched before.
</p>

<h2>Infrastructure</h2>
<p class="muted">Built with PHP and MySQL. No tracking, no ads, no accounts beyond a single admin login for the person running this instance.</p>

<?php require __DIR__ . '/includes/footer.php'; ?>
