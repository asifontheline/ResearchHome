document.addEventListener('DOMContentLoaded', function () {
  var btn = document.getElementById('fetch-btn');
  if (!btn) return;

  btn.addEventListener('click', function () {
    var url = document.getElementById('url').value.trim();
    var status = document.getElementById('fetch-status');
    if (!url) {
      status.textContent = 'Enter a URL first.';
      return;
    }
    status.textContent = 'Fetching…';
    btn.disabled = true;

    fetch('/fetch_metadata.php?url=' + encodeURIComponent(url))
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (data.error) {
          status.textContent = 'Could not fetch metadata: ' + data.error;
          return;
        }
        var fields = ['title', 'authors', 'abstract', 'source_name', 'published_date', 'image_url'];
        fields.forEach(function (f) {
          var el = document.getElementById(f);
          if (el && data[f] && !el.value) el.value = data[f];
        });
        status.textContent = 'Metadata filled in — review before saving.';
      })
      .catch(function () {
        status.textContent = 'Fetch failed. Fill in the fields manually.';
      })
      .finally(function () {
        btn.disabled = false;
      });
  });
});
