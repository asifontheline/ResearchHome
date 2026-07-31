</main>
<footer class="site-footer">
  🔭 ResHub (Research Hub) stores no content — only metadata and a link to the original source for every item.
  A personal, non-commercial research catalog. <a href="/credits.php">Sources &amp; credits</a>.
  <a href="/notrack.php">Opt out of tracking</a>.
  <a href="https://github.com/asifontheline/ResearchHome/issues/new/choose" target="_blank" rel="noopener noreferrer">Report a bug / suggest something</a>.
</footer>
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
