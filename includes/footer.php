</main>
<footer class="site-footer">
  🔭 ResHub (Research Hub) stores no content — only metadata and a link to the original source for every item.
  A personal, non-commercial research catalog. <a href="/credits.php">Sources &amp; credits</a>.
  <a href="/notrack.php">Opt out of tracking</a>.
  <a href="https://github.com/asifontheline/ResearchHome/issues/new/choose" target="_blank" rel="noopener noreferrer">Report a bug / suggest something</a>.
</footer>

<?php if (defined('FEEDBACK_EMAIL') && FEEDBACK_EMAIL): ?>
<div class="feedback-widget">
  <button type="button" class="feedback-fab" id="feedback-fab" aria-label="Send feedback" aria-expanded="false">&#128172;</button>
  <div class="feedback-panel" id="feedback-panel" hidden>
    <div class="feedback-panel-header">
      <strong>Send feedback</strong>
      <button type="button" class="feedback-close" id="feedback-close" aria-label="Close">&times;</button>
    </div>
    <form id="feedback-form">
      <textarea name="message" id="feedback-message" rows="4" placeholder="What's on your mind?" required></textarea>
      <input type="email" name="email" id="feedback-email" placeholder="Your email (optional, for a reply)">
      <button type="submit" id="feedback-submit">Send</button>
      <p class="feedback-status" id="feedback-status" role="status"></p>
    </form>
  </div>
</div>
<script>
(function () {
  var fab = document.getElementById('feedback-fab');
  var panel = document.getElementById('feedback-panel');
  var closeBtn = document.getElementById('feedback-close');
  var form = document.getElementById('feedback-form');
  var status = document.getElementById('feedback-status');
  var submitBtn = document.getElementById('feedback-submit');

  function open() {
    panel.hidden = false;
    fab.setAttribute('aria-expanded', 'true');
    fab.classList.add('feedback-fab-open');
    document.getElementById('feedback-message').focus();
  }
  function close() {
    panel.hidden = true;
    fab.setAttribute('aria-expanded', 'false');
    fab.classList.remove('feedback-fab-open');
  }

  fab.addEventListener('click', function () {
    if (panel.hidden) open(); else close();
  });
  closeBtn.addEventListener('click', close);

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    submitBtn.disabled = true;
    status.textContent = 'Sending…';
    status.className = 'feedback-status';

    var body = new URLSearchParams(new FormData(form));
    fetch('/feedback_send.php', { method: 'POST', body: body })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        submitBtn.disabled = false;
        if (data.ok) {
          status.textContent = 'Thanks — sent!';
          status.className = 'feedback-status feedback-status-ok';
          form.reset();
          setTimeout(close, 1500);
        } else {
          status.textContent = data.error || 'Something went wrong — try again.';
          status.className = 'feedback-status feedback-status-error';
        }
      })
      .catch(function () {
        submitBtn.disabled = false;
        status.textContent = 'Something went wrong — try again.';
        status.className = 'feedback-status feedback-status-error';
      });
  });
})();
</script>
<?php endif; ?>

<script>
// Verified via an actual headless-browser test against the live site
// (not guessed): setting the googtrans cookie and reloading reliably
// translates the page, with the widget fully hidden (display:none) and
// with zero interaction with Google's own dropdown UI — that dropdown's
// SIMPLE layout renders an unscrollable, non-responsive ~2600px-wide grid
// of every supported language with no viable way to contain it without
// clipping most of the list, so it's never shown at all. Our own compact
// <select> below drives translation via the cookie instead.
function googleTranslateElementInit() {
    new google.translate.TranslateElement({
        pageLanguage: 'en',
        autoDisplay: false,
        layout: google.translate.TranslateElement.InlineLayout.SIMPLE
    }, 'google_translate_element');
}

(function () {
    var ourSelect = document.getElementById('reshub-lang-select');
    if (!ourSelect) return;
    ourSelect.addEventListener('change', function () {
        // /en/en (source=target) for "Original" rather than deleting the
        // cookie — deleting it would make header.php's Accept-Language
        // auto-detect think no choice was ever made and silently
        // re-translate on the next page load.
        var lang = ourSelect.value;
        var expires = new Date(Date.now() + 30 * 24 * 3600 * 1000).toUTCString();
        document.cookie = 'googtrans=/en/' + lang + '; expires=' + expires + '; path=/';
        window.location.reload();
    });
})();
</script>
<script src="https://translate.google.com/translate_a/element.js?cb=googleTranslateElementInit" async></script>
</body>
</html>
