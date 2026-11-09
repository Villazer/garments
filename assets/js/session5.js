/* Session 5 progressive enhancements. Pages work without this file. */
(function () {
  'use strict';

  // Countdown: the server sends seconds left (computed with the database clock),
  // so a wrong clock on the visitor's phone cannot change the result.
  function format(sec) {
    if (sec <= 0) { return 'time is up'; }
    var d = Math.floor(sec / 86400), h = Math.floor((sec % 86400) / 3600), m = Math.floor((sec % 3600) / 60);
    var parts = [];
    if (d) { parts.push(d + (d === 1 ? ' day' : ' days')); }
    if (d || h) { parts.push(h + (h === 1 ? ' hour' : ' hours')); }
    if (!d) { parts.push(m + (m === 1 ? ' minute' : ' minutes')); }
    return parts.join(' ');
  }
  var started = Date.now();
  var nodes = document.querySelectorAll('[data-countdown]');
  function tick() {
    var elapsed = Math.floor((Date.now() - started) / 1000);
    nodes.forEach(function (n) {
      var left = parseInt(n.getAttribute('data-countdown'), 10) - elapsed;
      n.textContent = format(left);
    });
  }
  if (nodes.length) { tick(); setInterval(tick, 30000); }

  // Copy reference number
  document.querySelectorAll('[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var text = btn.getAttribute('data-copy');
      var done = function () { btn.textContent = 'Copied'; };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done, function () {});
      }
    });
  });

  // Show "requester name" only when someone other than the student is ordering
  var who = document.getElementById('requester');
  var wrap = document.getElementById('requester_name_wrap');
  if (who && wrap) {
    var sync = function () { wrap.hidden = (who.value === 'self'); };
    who.addEventListener('change', sync); sync();
  }
})();
