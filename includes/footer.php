</main>
<footer class="site-footer">
  🔭 ResHub (Research Hub) stores no content — only metadata and a link to the original source for every item.
  A personal, non-commercial research catalog. <a href="/credits.php">Sources &amp; credits</a>.
  <a href="/notrack.php">Opt out of tracking</a>.
</footer>
<script>
// Google's own dropdown/menu UI doesn't reflow responsively and can render
// wider than the viewport (confirmed — it was overflowing on real pages).
// So the widget itself stays hidden (#google_translate_element,
// display:none in header.php) — it still does the actual translation work
// (that part is cookie-driven, not UI-driven — this is the same mechanism
// the server-side Accept-Language auto-detect in header.php already relies
// on), we just never show its own UI. Trying to drive it by finding and
// manipulating Google's internal <select class="goog-te-combo"> was tried
// first and didn't reliably work — that element's availability/structure
// isn't something to depend on. Setting the googtrans cookie and reloading
// is the same thing Google's own dropdown does under the hood, and is what
// the auto-detect feature already proves works.
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
        // /en/en (source=target) rather than deleting the cookie — deleting
        // it would make header.php's Accept-Language auto-detect think no
        // choice was ever made and silently re-translate on the very next
        // page load, undoing "Original" the moment you navigate anywhere.
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
