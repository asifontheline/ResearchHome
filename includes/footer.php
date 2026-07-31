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
// display:none in header.php) — it still does the actual translation work,
// we just never show its UI. Our own <select> in the header drives it
// instead, by finding the <select class="goog-te-combo"> Google's script
// injects and dispatching a change event on it, same mechanism its own
// dropdown would trigger.
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
        // The combo box only exists once Google's script has finished
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
                clearInterval(interval); // ~10s, give up quietly
            }
        }, 250);
    });
})();
</script>
<script src="https://translate.google.com/translate_a/element.js?cb=googleTranslateElementInit" async></script>
</body>
</html>
