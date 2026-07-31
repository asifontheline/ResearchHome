</main>
<footer class="site-footer">
  🔭 ResHub (Research Hub) stores no content — only metadata and a link to the original source for every item.
  A personal, non-commercial research catalog. <a href="/credits.php">Sources &amp; credits</a>.
  <a href="/notrack.php">Opt out of tracking</a>.
  <a href="https://github.com/asifontheline/ResearchHome/issues/new/choose" target="_blank" rel="noopener noreferrer">Report a bug / suggest something</a>.
</footer>
<script>
// Two attempts at hiding Google's widget and driving it with custom JS
// (a select element manipulating Google's internal combo box) both proved
// unreliable in practice — that internal structure isn't something to
// depend on, and there's no way to browser-test DOM-hacking approaches
// like that from here to verify them before shipping. Back to the
// simplest, standard, documented-to-work configuration: Google's own
// widget UI, shown directly, doing exactly what it's designed to do.
// Overflow is handled with plain CSS containment in style.css instead of
// trying to hide/replace the widget.
function googleTranslateElementInit() {
    new google.translate.TranslateElement({
        pageLanguage: 'en',
        autoDisplay: false,
        layout: google.translate.TranslateElement.InlineLayout.SIMPLE
    }, 'google_translate_element');
}
</script>
<script src="https://translate.google.com/translate_a/element.js?cb=googleTranslateElementInit" async></script>
</body>
</html>
