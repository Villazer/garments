(function () {
  // double-submit guard: disable submit buttons once a data-once form is sent
  document.querySelectorAll('form[data-once]').forEach(function (f) {
    f.addEventListener('submit', function (ev) {
      if (f.dataset.sent === '1') { ev.preventDefault(); return; }
      f.dataset.sent = '1';
      setTimeout(function () {
        f.querySelectorAll('button[type=submit]').forEach(function (b) { b.disabled = true; });
      }, 0);
    });
  });
  // pageshow: re-enable after using the browser Back button
  window.addEventListener('pageshow', function (e) {
    if (e.persisted) {
      document.querySelectorAll('form[data-once]').forEach(function (f) {
        f.dataset.sent = '0';
        f.querySelectorAll('button[type=submit]').forEach(function (b) { b.disabled = false; });
      });
    }
  });
  document.querySelectorAll('[data-print]').forEach(function (b) {
    b.addEventListener('click', function () { window.print(); });
  });
  var m = document.querySelector('[data-autoprint]');
  if (m) { window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); }); }
})();
