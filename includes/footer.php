</main>
<footer class="site-footer">
  🔭 ResHub (Research Hub) stores no content — only metadata and a link to the original source for every item.
  A personal, non-commercial research catalog. <a href="/credits.php">Sources &amp; credits</a>.
  <a href="/notrack.php">Opt out of tracking</a>.
</footer>
<script>
// Google's own dropdown/menu UI doesn't reflow responsively and can render
// wider than the viewport (confirmed — it was overflowing on real pages),
// so it's never shown — but the container (#google_translate_element) must
// stay visually-hidden-yet-rendered (.goog-te-hidden in style.css), not
// display:none. A fully hidden container caused inconsistent init: some
// languages silently failed to apply, and a reload-based cookie approach
// (tried first) still had that same underlying flakiness plus a jarring
// full-page-reload flash on every change. With proper (visible-but-clipped)
// init, driving Google's own internal <select class="goog-te-combo">
// directly works reliably and applies instantly, no reload.
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
        var lang = ourSelect.value;

        // Cookie too — not what applies translation on *this* page (the
        // combo-box below does that, instantly), but what makes the choice
        // stick on the *next* page navigated to, same mechanism the
        // Accept-Language auto-detect in header.php relies on. /en/en
        // (source=target) for "Original" rather than deleting it, so the
        // auto-detect logic doesn't mistake "explicitly chose English" for
        // "never chose anything" and silently re-translate later.
        var expires = new Date(Date.now() + 30 * 24 * 3600 * 1000).toUTCString();
        document.cookie = 'googtrans=/en/' + lang + '; expires=' + expires + '; path=/';

        // Combo box only exists once Google's script has finished
        // initializing — poll briefly rather than assuming it's ready.
        var tries = 0;
        var interval = setInterval(function () {
            var combo = document.querySelector('#google_translate_element select.goog-te-combo');
            tries++;
            if (combo) {
                clearInterval(interval);
                combo.value = lang;
                combo.dispatchEvent(new Event('change'));
            } else if (tries > 40) {
                clearInterval(interval); // ~10s, give up quietly — cookie above still applies on next navigation
            }
        }, 250);
    });
})();
</script>
<script src="https://translate.google.com/translate_a/element.js?cb=googleTranslateElementInit" async></script>
</body>
</html>
